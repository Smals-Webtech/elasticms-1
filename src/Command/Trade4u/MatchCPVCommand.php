<?php

namespace App\Command\Trade4u;

use EMS\CommonBundle\Elasticsearch\Client;
use Elasticsearch\Endpoints\Indices\Analyze;
use EMS\CommonBundle\Command\CommandInterface;
use EMS\CommonBundle\Elasticsearch\Document\Document;
use EMS\CommonBundle\Elasticsearch\Response\Response;
use EMS\CommonBundle\Service\ElasticaService;
use EMS\CoreBundle\Form\Form\RevisionType;
use EMS\CoreBundle\Service\DataService;
use EMS\CoreBundle\Service\EnvironmentService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Form\FormFactoryInterface;

class MatchCPVCommand extends Command implements CommandInterface
{
    /** @var Client */
    private $client;
    /** @var EnvironmentService */
    private $environmentService;
    /** @var DataService */
    private $dataService;
    /** @var FormFactoryInterface */
    private $formFactory;

    protected static $defaultName = 'trade4u:match:cpv';

    private ElasticaService $elasticaService;

    public function __construct(
        Client $client,
        EnvironmentService $environmentService,
        DataService $dataService,
        FormFactoryInterface $formFactory,
        ElasticaService $elasticaService
    ) {
        parent::__construct();
        $this->client = $client;
        $this->environmentService = $environmentService;
        $this->dataService = $dataService;
        $this->formFactory = $formFactory;
        $this->elasticaService = $elasticaService;
    }

    protected function configure()
    {
        parent::configure();
        $this
            ->setDescription('Match cpv for products')
            ->addArgument('environment', InputArgument::OPTIONAL, 'environment', 'preview')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $style = new SymfonyStyle($input, $output);
        $style->title('Trade4u match CPV');

        $environmentName = $input->getArgument('environment');
        $environment = $this->environmentService->getByName($environmentName);

        if (!$environment) {
            throw new \RuntimeException(\sprintf('environment %s not found', $environmentName));
        }

        $cpvs = $this->getCPVs($style, $environment->getAlias());
        $pBar = $style->createProgressBar(\count($cpvs));
        $pBar->start();

        $style->section('matching');

        foreach ($cpvs as $cpv) {
            foreach ($this->matchProducts($cpv, $environment->getAlias()) as $product) {
                $match = $this->getMatch($cpv['_source'], $product['_source']);
                $this->save($product, 'cpv:'.$cpv['_id'], $match);
            }
            $pBar->advance();
        }

        $pBar->finish();
        $pBar->clear();
    }

    private function matchProducts(array $cpv, string $index): \Generator
    {
        $body = [
            'query' => [
                'bool' => [
                    'minimum_should_match' => 1,
                    'should' => [
                        ['term' => ['title_nl.raw' => $cpv['_source']['title_nl']]],
                        ['term' => ['title_fr.raw' => $cpv['_source']['title_fr']]],
                        ['term' => ['title_en.raw' => $cpv['_source']['title_en']]],
                    ],
                ],
            ],
        ];
        $search = $this->elasticaService->convertElasticsearchBody([$index], ['product'], $body);
        $response = Response::fromResultSet($this->elasticaService->search($search));

        /** @var Document $product */
        foreach ($response->getDocuments() as $product) {
            yield $product->getRaw();
        }
    }

    private function getCPVs(SymfonyStyle $style, string $index): array
    {
        $params = [
            'index' => $index,
            'type' => 'cpv',
            'size' => 50,
            '_source' => ['title_nl', 'title_fr', 'title_en'],
        ];

        $style->section("Analyzing cpv's");
        $pg = $style->createProgressBar();
        $pg->start();

        $result = [];
        $search = $this->elasticaService->convertElasticsearchSearch($params);
        $scroll = $this->elasticaService->scroll($search, '5s');
        $languages = ['nl', 'fr', 'en'];

        foreach ($scroll as $resultSet) {
            foreach ($resultSet as $item) {
                if (false === $item) {
                    continue;
                }
                foreach ($languages as $lang) {
                    $endpoint = new Analyze();
                    $endpoint->setBody([
                        'tokenizer' => 'keyword',
                        'char_filter' => ['html_strip'],
                        'filter' => ['lowercase', 'asciifolding'],
                        'text' => $item->getSource()['title_'.$lang],
                    ]);
                    $analyze = $this->client->requestEndpoint($endpoint);

                    $hit['_source']['title_'.$lang] = $analyze->getData()['tokens'][0]['token'];
                }

                $result[] = $hit;
                $pg->advance();
            }
        }

        $pg->finish();
        $style->writeln(2);

        return $result;
    }

    private function getMatch(array $cpv, array $product): int
    {
        $a = [$cpv['title_nl'], $cpv['title_fr'], $cpv['title_en']];
        $b = \array_map('strtolower', [$product['title_nl'], $product['title_fr'], $product['title_en']]);

        $diff = \array_diff($b, $a);

        return 3 - \count($diff);
    }

    private function save(array $product, string $cpv, int $cpvMatch)
    {
        $revision = $this->dataService->initNewDraft('product', $product['_id'], null, 'MATCH_JOB');
        $rawData = $revision->getRawData();
        $rawData['cpv'] = $cpv;
        $rawData['cpv_match'] = $cpvMatch;

        if (null == $revision->getDatafield()) {
            $this->dataService->loadDataStructure($revision);
        }

        $builder = $this->formFactory->createBuilder(RevisionType::class, $revision);
        $form = $builder->getForm();

        $revision->setRawData($rawData);
        $this->dataService->finalizeDraft($revision, $form, 'MATCH_JOB', false);
    }
}
