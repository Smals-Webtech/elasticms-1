<?php

namespace App\Command\Trade4u;

use EMS\CommonBundle\Service\ElasticaService;
use EMS\CoreBundle\Form\Form\RevisionType;
use EMS\CoreBundle\Service\ContentTypeService;
use EMS\CoreBundle\Service\DataService;
use EMS\CoreBundle\Service\EnvironmentService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Form\FormFactoryInterface;

class MathJobCommand extends Command
{
    /** @var DataService */
    private $dataService;
    /** @var EnvironmentService */
    private $envService;
    /** @var ContentTypeService */
    private $contentTypeService;
    /** @var FormFactoryInterface */
    protected $formFactory;
    /** @var ElasticaService */
    private ElasticaService $elasticaService;

    public function __construct(
        DataService $dataService,
        EnvironmentService $envService,
        ContentTypeService $contentTypeService,
        FormFactoryInterface $formFactory,
        ElasticaService $elasticaService
    ) {
        parent::__construct();
        $this->dataService = $dataService;
        $this->envService = $envService;
        $this->contentTypeService = $contentTypeService;
        $this->formFactory = $formFactory;
        $this->elasticaService = $elasticaService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('trade4u:match')
            ->setDescription('calculate the matches/opportunities for companies')
            ->addArgument('matchId', InputArgument::REQUIRED, 'match id')
            ->addArgument('environment', InputArgument::OPTIONAL, 'environment', 'preview')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $environmentName = $input->getArgument('environment');
        $environment = $this->envService->getByName($environmentName);

        if (!$environment) {
            throw new \RuntimeException(\sprintf('environment %s not found', $environmentName));
        }
        $matchId = $input->getArgument('matchId');
        if (!\is_string($matchId)) {
            throw new \RuntimeException('Unexpected not string match id');
        }
        $index = $environment->getAlias();
        $document = $this->elasticaService->getDocument($index, 'match', $matchId);
        $source = $document->getSource();

        if (!$source['match_activities'] && !$source['match_products'] && !$source['match_cpv'] && !$source['match_countries'] && !$source['match_domain_abonnement']) {
            return; //nothing to match;
        }

        $matches = [];

        foreach ($source['linked_opportunities'] as $opportunityId) {
            $split = \preg_split('/:/', $opportunityId);
            $opportunity = $this->elasticaService->getDocument($index, $split[0], $split[1]);

            $matches[] = [
                'opportunity' => $opportunityId,
                'companies' => $this->match($opportunity->getSource(), $source, $index, $output),
            ];
        }

        if ($matches) {
            $this->save($document->getRaw(), $matches);
        }
    }

    /**
     * @param string $index
     *
     * @return array
     */
    private function match(array $opportunity, array $match, $index, OutputInterface $output)
    {
        $queryMust = [];
        $shouldCountries = [];

        $activities = isset($opportunity['activities']) ? $opportunity['activities'] : [];
        $products = isset($opportunity['products']) ? $opportunity['products'] : [];
        $cpv = isset($opportunity['cpv']) ? $opportunity['cpv'] : [];
        $countries = isset($opportunity['countries_active']) ? $opportunity['countries_active'] : [];
        $abonnements = isset($match['match_domain_abonnement']) ? $match['match_domain_abonnement'] : [];

        $this->buildMatch($queryMust, $match['match_activities'], $activities, 'domains.activities');
        $this->buildMatch($queryMust, $match['match_products'], $products, 'domains.products');
        $this->buildMatch($queryMust, $match['match_cpv'], $cpv, 'domains.cpv');
        $this->buildMatch($shouldCountries, $match['match_countries'], $countries, 'domains.countries_active');
        $this->buildMatch($shouldCountries, $match['match_countries'], $countries, 'domains.countries_interested');

        if ($shouldCountries) {
            $queryMust[] = ['bool' => ['should' => $shouldCountries]];
        }

        $query = [
            'bool' => [
                'must' => [
                    [
                        'nested' => [
                            'path' => 'domains',
                            'query' => [
                                'bool' => [
                                    'must' => $queryMust,
                                ],
                            ],
                        ],
                    ],
                    ['terms' => ['domain' => ['company_domain:ac17d74d76b73ef59f672e500931602d', 'company_domain:AWy0ZX2floHTbBW_vL0h']]],
                    ['term' => ['legal_status' => 'legal_status:11e80d063b64e630dac8af80e6cff910']],
                ],
            ],
        ];

        if (!empty($abonnements)) {
            $query['bool']['must'][] = ['terms' => ['trade4u_abonnement' => $abonnements]];
        }

        $output->writeln(\json_encode($query));

        $scrollTimeout = '5s';
        $params = [
            'index' => $index,
            'type' => 'company',
            'size' => 50,               // how many results *per shard* you want back
            '_source' => false,
            'body' => [
                'query' => $query,
            ],
        ];

        $companies = [];
        $search = $this->elasticaService->convertElasticsearchSearch($params);
        $scroll = $this->elasticaService->scroll($search, '5s');


        foreach ($scroll as $resultSet) {
            foreach ($resultSet as $result) {
                if (false === $result) {
                    continue;
                }
                $companies[] = 'company:'.$result->getId();
            }
        }

        return $companies;
    }

    /**
     * @param bool   $doMatch          checkbox value CT match
     * @param array  $opportunityLinks linked children in opportunity
     * @param string $relation         property name CT company
     *
     * @return bool
     */
    private function buildMatch(array &$query, $doMatch, $opportunityLinks, $relation)
    {
        if (!$doMatch || null == $opportunityLinks) {
            return false;
        }

        $query[] = ['terms' => [$relation => $opportunityLinks]];

        return true;
    }

    private function save(array $document, array $matches)
    {
        $revision = $this->dataService->initNewDraft('match', $document['_id'], null, 'MATCH_JOB');
        $rawData = $revision->getRawData();
        $rawData['matches'] = $matches;

        if (null == $revision->getDatafield()) {
            $this->dataService->loadDataStructure($revision);
        }

        $builder = $this->formFactory->createBuilder(RevisionType::class, $revision);
        $form = $builder->getForm();

        $revision->setRawData($rawData);
        $this->dataService->finalizeDraft($revision, $form, 'MATCH_JOB');
    }
}
