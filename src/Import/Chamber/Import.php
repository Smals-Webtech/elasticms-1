<?php

namespace App\Import\Chamber;

use App\Import\Chamber\XML\SearchActor;
use EMS\CommonBundle\Elasticsearch\Document\Document;
use EMS\CommonBundle\Elasticsearch\Exception\NotSingleResultException;
use EMS\CommonBundle\Elasticsearch\Response\Response;
use EMS\CommonBundle\Service\ElasticaService;
use Psr\Log\LoggerInterface;

class Import
{
    /** @var LoggerInterface */
    private $logger;
    /** @var string */
    private $rootDir;

    /** @var string */
    private $environment;
    private $legislatures = [];
    private $legislatureIds = [];
    private $parties = [];
    private $type;
    /** @var SearchActor */
    public $searchActor;
    /** @var bool */
    private $dryPdf;
    /** @var bool */
    private $keepCv;

    const EMS_INSTANCE_ID = 'webchamber_';
    /** @var ElasticaService */
    private ElasticaService $elasticaService;

    public function __construct(ElasticaService $elasticaService, LoggerInterface $logger, string $dir, string $type, string $environment, bool $dryPdf, bool $keepCv)
    {
        $this->logger = $logger;
        $this->dryPdf = $dryPdf;
        $this->keepCv = $keepCv;
        $this->type = $type;
        $this->elasticaService = $elasticaService;
        $this->rootDir = (Model::TYPE_ACTR === $type) ? $dir.'/../../' : $dir.'/../../..';

        $this->environment = $environment;
        $this->legislatures = $this->buildLegislatures();
        $this->legislatureIds = \array_keys($this->legislatures);

        $this->searchActor = new SearchActor($this);
    }

    public function search(array $body): array
    {
        $search = $this->elasticaService->convertElasticsearchBody([self::EMS_INSTANCE_ID.'ma_'.$this->environment], [], $body);
        return $this->elasticaService->search($search)->getResponse()->getData();
    }

    public function get(string $index, string $id): array
    {
        return $this->elasticaService->getDocument($index, null, Model::createId(Model::TYPE_ACTR, $id))->getRaw();
    }

    public function existLegislature(int $id): bool
    {
        return \array_key_exists($id, $this->legislatures);
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function getLegislatureDates(array $legislatureIds): array
    {
        $dates = [];

        foreach ($legislatureIds as $id) {
            $legislature = $this->getLegislature($id);

            $dates[] = $legislature['start'] ?? null;
            $dates[] = $legislature['end'] ?? null;
        }

        \array_filter($dates);
        \rsort($dates);

        return $dates;
    }

    public function getLegislaturesIds(): array
    {
        return $this->legislatureIds;
    }

    public function getActiveLegislatureId(): int
    {
        $legislatures = $this->legislatures;
        $first = \array_shift($legislatures);

        return (int) $first['id'];
    }

    public function getLegislature(int $id): array
    {
        if (MODEL::TYPE_GENESIS === $this->type) {
            return [];
        }

        if (!$this->existLegislature($id)) {
            throw new \Exception(\sprintf('Legislature unknown %s', $id));
        }

        return $this->legislatures[$id];
    }

    public function getLegislatureByDate(\DateTime $date): array
    {
        foreach ($this->legislatures as $legislature) {
            $start = \DateTime::createFromFormat('Y-m-d', $legislature['start']);
            $end = \DateTime::createFromFormat('Y-m-d', $legislature['end']);

            if ($date >= $start && $date <= $end) {
                return $legislature;
            }
        }

        throw new \Exception(\sprintf('Legislature unknown for date %s', $date->format('d-m-Y')));
    }

    public function getCommission(string $docName, int $legislature)
    {
        $search =$this->elasticaService->convertElasticsearchBody([
            self::EMS_INSTANCE_ID.'ma_'.$this->environment
        ], [], [
            'index' => self::EMS_INSTANCE_ID.'ma_'.$this->environment,
            'type' => 'doc',
            'body' => [
                'size' => 1,
                'query' => [
                    'bool' => [
                        'must' => [
                            ['term' => ['_contenttype' => ['value' => 'orgn']]],
                            ['term' => ['type_orgn' => ['value' => 'commission']]],
                            ['term' => ['doc_name' => ['value' => $docName]]],
                            ['term' => ['legislature' => ['value' => $legislature]]],
                        ],
                    ],
                ],
            ],
        ]);

        try {
            return \sprintf('orgn:%s', $this->elasticaService->singleSearch($search)->getId());
        } catch (NotSingleResultException $e) {
            return null;
        }
    }

    public function getParty(string $emsLink): ?string
    {
        if (null == $this->parties) {
            $this->buildParties();
        }

        return isset($this->parties[$emsLink]) ? $this->parties[$emsLink]['id'] : null;
    }

    public function getPartyName(string $id, string $locale): ?string
    {
        if (null == $this->parties) {
            $this->buildParties();
        }

        return $this->parties[$id]['title_'.$locale] ?? null;
    }

    private function buildParties(): void
    {
        $search = $this->search(['size' => 1000, 'query' => ['term' => ['type_orgn' => ['value' => 'party']]]]);

        foreach ($search['hits']['hits'] as $hit) {
            $this->parties['orgn:'.$hit['_id']] = [
                'title_nl' => $hit['_source']['title_nl'],
                'title_fr' => $hit['_source']['title_fr'],
            ];

            $orgnIds = $hit['_source']['orgn_ids'] ?? [];

            foreach ($orgnIds as $id) {
                $this->parties[$id] = ['id' => 'orgn:'.$hit['_id']];
            }
        }
    }

    private function buildLegislatures(): array
    {
        $this->logger->info('Getting legislations');
        $search = $this->elasticaService->convertElasticsearchSearch([
            'index' => self::EMS_INSTANCE_ID.$this->environment,
            'type' => 'legislature',
            'size' => 100,
            'body' => ['sort' => ['date_start' => 'desc']],
        ]);
        $legislatures = [];

        $response = Response::fromResultSet($this->elasticaService->search($search));
        /** @var Document $document */
        foreach ($response->getDocuments() as $document) {
            $legislatures[$document->getId()] = [
                'id' => $document->getId(),
                'start' => \DateTime::createFromFormat('Y/m/d', $document->getSource()['date_start'])->format('Y-m-d'),
                'end' => \DateTime::createFromFormat('Y/m/d', $document->getSource()['date_end'])->format('Y-m-d'),
            ];
        }

        return $legislatures;
    }

    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    public function isAttachmentIndexingEnabled(): bool
    {
        return !$this->dryPdf;
    }

    public function hasKeepCv(): bool
    {
        return $this->keepCv;
    }
}
