<?php

declare(strict_types=1);

namespace App\Command\TechSearch;

use Elastica\Client;
use EMS\CommonBundle\Command\CommandInterface;
use EMS\CommonBundle\Common\EMSLink;
use EMS\CommonBundle\Elasticsearch\Exception\NotSingleResultException;
use EMS\CommonBundle\Service\ElasticaService;
use EMS\CoreBundle\Elasticsearch\Bulker;
use EMS\CoreBundle\Service\EnvironmentService;
use EMS\CoreBundle\Service\IndexService;
use EMS\CoreBundle\Service\Mapping;
use GuzzleHttp\Client as HttpClient;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

final class ImportSitemap extends Command implements CommandInterface
{
    /** @var Bulker */
    private $bulker;
    /** @var Client */
    private $client;
    /** @var EnvironmentService */
    private $environmentService;
    /** @var HttpClient */
    private $httpClient;
    /** @var SymfonyStyle */
    private $io;
    /** @var string */
    private $searchIndex;
    /** @var HttpClient */
    private $tikaClient;
    /** @var string */
    private $tikaServer;
    /** @var Url[] */
    private $urls = [];
    /** @var array */
    private $emsLinks = [];

    protected static $defaultName = 'ems:job:tech-search:sitemap';

    private const ARGUMENT_EMS_LINK = 'emsLink';
    private const OPTION_RESET = 'reset';
    private const OPTION_NO_SSL = 'dontVerifySsl';
    private const INDEX = 'job_tech_search_sitemap';

    private ElasticaService $elasticaService;

    private IndexService $indexService;

    private Mapping $mapping;

    public function __construct(
        Bulker $bulker,
        Client $client,
        EnvironmentService $environmentService,
        ElasticaService $elasticaService,
        IndexService $indexService,
        Mapping $mapping,
        string $tikaServer
    ) {
        parent::__construct();
        $this->client = $client;
        $this->bulker = $bulker;
        $this->environmentService = $environmentService;
        $this->tikaServer = $tikaServer;
        $this->elasticaService = $elasticaService;
        $this->indexService = $indexService;
        $this->mapping = $mapping;
    }

    protected function configure()
    {
        parent::configure();
        $this
            ->setDescription('TechSearch import site map command')
            ->addArgument(
                self::ARGUMENT_EMS_LINK,
                InputArgument::REQUIRED,
                'ems://object:import:ouuid'
            )
            ->addOption(
                self::OPTION_RESET,
                null,
                InputOption::VALUE_NONE,
                \sprintf('start fresh will drop index : %s', self::INDEX))
            ->addOption(
                self::OPTION_NO_SSL,
                null,
                InputOption::VALUE_NONE,
                'Do not verify SSL certificate'
            )
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title('Tech Search import');

        $this->httpClient = new HttpClient(['verify' => (!$input->getOption(self::OPTION_NO_SSL))]);
        $this->tikaClient = new HttpClient(['base_uri' => $this->tikaServer, 'timeout' => 30]);

        if (200 !== $hello = $this->tikaClient->get('/tika')->getStatusCode()) {
            throw new \RuntimeException(\sprintf('Tika server (%s) down ? [%d]', $this->tikaServer, $hello));
        }

        $environment = $this->environmentService->getByName('preview');
        $this->searchIndex = $environment->getAlias();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $emsLink = EMSLink::fromText($input->getArgument('emsLink'));
        $environment = $this->environmentService->getByName('preview');
        $document = $this->elasticaService->getDocument($environment->getAlias(), $emsLink->getContentType(), $emsLink->getOuuid());

        $sitemapResponse = $this->download($document->getSource()['url']);
        $this->setUrls($sitemapResponse);
        $this->io->section(\sprintf('Parsed %d urls', \count($this->urls)));

        if ($input->getOption(self::OPTION_RESET)) {
            $this->reset();
        }

        $this->bulker->setLogger(new ConsoleLogger($output));
        $this->bulker->setSize(1);

        $emsLinkSitemap = \sprintf('%s:%s', $emsLink->getContentType(), $emsLink->getOuuid());
        $this->import($emsLinkSitemap);

        $this->io->newLine();

        return 1;
    }

    private function convertUrl(Url $url): array
    {
        $data = $url->toArray();

        $data['owner'] = $this->getEMSLink('owner', $data['owner']);
        $data['type'] = $this->getEMSLink('type', $data['type']);
        $data['facets'] = \array_map(function ($facet) {
            return $this->getEMSLink('facet', $facet);
        }, $data['facets']);

        $downloadResponse = $this->download($url->getUrl());
        $content = $this->extractContent($downloadResponse)->getBody()->getContents();

        $data['file'] = $this->getFileInfoFromResponse($downloadResponse);
        foreach ($url->getLanguages() as $language) {
            $data['content_'.$language] = $content;
        }

        return \array_filter($data);
    }

    private function download(string $url): ResponseInterface
    {
        $this->io->write(\sprintf('Downloading %s', $url));
        $response = $this->httpClient->get($url);

        if (200 !== $statusCode = $response->getStatusCode()) {
            throw new \RuntimeException(\sprintf('%s resulted in %s', $url, $statusCode));
        }

        return $response;
    }

    private function extractContent(ResponseInterface $response): ?ResponseInterface
    {
        try {
            return $this->tikaClient->put('/tika', [
                'body' => $response->getBody()->__toString(),
                'headers' => ['Accept' => 'text/plain'],
            ]);
        } catch (\Exception $e) {
            $this->io->error(\sprintf('Failed to extract content: %s', $e->getMessage()));

            return null;
        }
    }

    private function getFileInfoFromResponse(ResponseInterface $response): array
    {
        $contentLength = $response->getHeaderLine('Content-Length');
        $contentDisposition = $response->getHeaderLine('Content-Disposition');

        if ($contentDisposition) {
            \preg_match('/filename="(?\'filename\'.+)"/', $contentDisposition, $matches);
            $filename = $matches['filename'];
        } else {
            $filename = null;
        }

        return \array_filter([
            'filename' => $filename,
            'filesize' => '' != $contentLength ? $contentLength : $response->getBody()->getSize(),
            'mimetype' => $response->getHeader('Content-Type'),
        ]);
    }

    private function getEMSLink(string $contentType, string $identifier): ?string
    {
        if (\array_key_exists($identifier, $this->emsLinks)) {
            return $this->emsLinks[$identifier];
        }

        $search = $this->elasticaService->convertElasticsearchBody([$this->searchIndex], [$contentType], ['query' => ['term' => ['identifier' => $identifier]]]);

        try {
            $result = $this->elasticaService->singleSearch($search);
            $this->emsLinks[$identifier] = $result->getEmsId();

            return $this->emsLinks[$identifier];
        } catch (NotSingleResultException $e) {
            $this->io->warning(\vsprintf('Creation ems link failed result (%d) for "%s:%s"', [
                $e->getTotal(),
                $contentType,
                $identifier,
            ]));
        }

        return null;
    }

    private function import(string $emsLinkSitemap): void
    {
        $this->io->section('Start importing urls');
        $progressBar = $this->io->createProgressBar(\count($this->urls));
        $progressBar->start();

        foreach ($this->urls as $url) {
            try {
                $data = $this->convertUrl($url);
                $data['sitemap'] = $emsLinkSitemap;

                $this->bulker->index('url', \sha1('url_'.$url->getUrl()), self::INDEX, $data);
                $progressBar->advance();
            } catch (\Exception $e) {
                $this->io->error(\sprintf('Failed importing %s : %s', $url->getUrl(), $e->getMessage()));
            }
        }

        $this->bulker->send(true);
        $progressBar->finish();
    }

    private function reset(): void
    {
        $this->io->section(\sprintf('Resetting index %s', self::INDEX));

        if ($this->indexService->hasIndex(self::INDEX)) {
            $this->indexService->deleteIndex(self::INDEX);
        }

        $body = \json_decode(\file_get_contents(__DIR__.'/tech_search_mapping.json'), true);

        $this->mapping->updateMapping(self::INDEX, $body, 'url');
    }

    private function setUrls(ResponseInterface $response): void
    {
        $encoder = new XmlEncoder();
        $xml = $encoder->decode($response->getBody()->getContents(), XmlEncoder::FORMAT);

        $urls = isset($xml['url']['loc']) ? [$xml['url']] : $xml['url']; //nest if only 1

        foreach ($urls as $data) {
            try {
                $this->urls[] = new Url($data);
            } catch (\Exception $e) {
                $this->io->error(\sprintf('Error %s parsing data %s', $e->getMessage(), \json_encode($data)));
            }
        }
    }
}
