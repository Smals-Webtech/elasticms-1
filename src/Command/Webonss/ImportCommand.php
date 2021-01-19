<?php

declare(strict_types=1);

namespace App\Command\Webonss;

use Elasticsearch\Common\Exceptions\NoNodesAvailableException;
use EMS\CoreBundle\Elasticsearch\Bulker;
use EMS\CoreBundle\Elasticsearch\Indexer;
use EMS\CommonBundle\Storage\StorageManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use EMS\CommonBundle\Elasticsearch\Document\Document;

class ImportCommand extends Command
{
    /** @var Bulker */
    private $bulker;
    /** @var string */
    private $csv;
    /** @var string */
    private $filesDirectory;
    /** @var Indexer */
    private $indexer;
    /** @var LoggerInterface */
    private $logger;
    /** @var StorageManager */
    private $storageManager;
    /** @var SymfonyStyle */
    private $style;

    protected static $defaultName = 'ems:job:webonss:import';

    // php bin\console ems:job:webonss:import ----> Import into 2 indexes
    // php bin\console ems:contenttype:migrate statwork_themes theme ---> Add them to elasticms
    // php bin\console ems:contenttype:migrate statwork_publications publication ---> Add them to elasticms
    // https://github.com/ems-project/EMSApiClient ---> Upload files using EMSApiClient

    const INDEX_THEMES = 'statwork_themes';
    const INDEX_PUBLICATIONS = 'statwork_publications';
    const CONTENTTYPE_THEME = 'theme';
    const CONTENTTYPE_PUBLICATION = 'publication';

    public function __construct(Bulker $bulker, Indexer $indexer, StorageManager $storageManager)
    {
        $this->bulker = $bulker;
        $this->indexer = $indexer;
        $this->storageManager = $storageManager;

        parent::__construct();
    }

    protected function configure()
    {
        parent::configure();
        $this
            ->setDescription('Import for WebONSS')
            ->addArgument('csv', InputArgument::OPTIONAL, 'A CSV file with the list of files', 'C:\dev\import\statwork\files.csv')
            ->addArgument('filesDirectory', InputArgument::OPTIONAL, 'The directory of the files to be migrated', 'C:\dev\import\statwork\webonss.assets.statistics\statistics');
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $csv = $input->getArgument('csv');
        if (!\file_exists($csv)) {
            throw new \Error(sprintf('File %s does not exist', $csv));
        }

        $filesDirectory = $input->getArgument('filesDirectory');
        if (!\file_exists($filesDirectory)) {
            throw new \Error(sprintf('File %s does not exist', $filesDirectory));
        }
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->style = new SymfonyStyle($input, $output);
        $this->logger = new ConsoleLogger($output);
        $this->bulker->setLogger($this->logger);
        $this->indexer->setLogger($this->logger);
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->style->title('WebONSS import');

        $output->writeln('Running');

        $this->csv = $input->getArgument('csv');
        $this->filesDirectory = $input->getArgument('filesDirectory');

        $csvFile = \file($this->csv);

        $documentIds = [];
        foreach ($csvFile as $file) {
            $id = explode(';', $file)[7];
            if(!in_array($id, $documentIds)) {
                $documentIds[] = $id;
            }
        }

        $documentArrays = [];
        foreach ($documentIds as $id) {
            $documentArrays[trim($id)] = [
            ];
        }

        foreach ($csvFile as $file) {
            $fileArray = explode(';', $file);
            $fileName = $fileArray[0];
            $fileExtension = $fileArray[1];
            $publicationType = $fileArray[2];
            $filePath = $fileArray[3];
            $year = $fileArray[4];
            $trimester = $fileArray[5];
            $language = strtolower($fileArray[6]);
            $targetId = trim($fileArray[7]);
            $publicationTypeReference = $fileArray[8];

            $fullPath = $this->filesDirectory . DIRECTORY_SEPARATOR . $filePath . DIRECTORY_SEPARATOR . $fileName;
            $content = \file_get_contents($fullPath);
            $mime = \mime_content_type($fullPath);


            if ($publicationType == 'periodic') {
                $documentArrays[$targetId]['_id'] = trim($targetId);
                $documentArrays[$targetId]['_index'] = self::INDEX_THEMES;
                $documentArrays[$targetId]['_type'] = self::CONTENTTYPE_THEME;
                $documentArrays[$targetId]['_source']['archives'][] = [
                    'archive_file' => [
                        'filename' => $fileName,
                        'filesize' => \filesize($fullPath),
                        'mimetype' => $mime,
                        'sha1' => $this->storageManager->saveContents($content, $mime, $fileName, 2)
                    ],
                    'archive_language' => strtolower($language),
                    'archive_quarter' => $trimester,
                    'archive_type' => $publicationTypeReference,
                    'archive_year' => $year
                ];
            } else {
                $documentArrays[$targetId]['_id'] = trim($targetId);
                $documentArrays[$targetId]['_index'] = self::INDEX_PUBLICATIONS;
                $documentArrays[$targetId]['_type'] = self::CONTENTTYPE_PUBLICATION;
                $documentArrays[$targetId]['_source']['file_' . $language] = [
                    '_author' => null,
                    '_content' => null,
                    '_date' => null,
                    '_language' => null,
                    '_title' => null,
                    'filename' => $fileName,
                    'filesize' => \filesize($fullPath),
                    'mimetype' => $mime,
                    'sha1' => $this->storageManager->saveContents($content, $mime, $fileName, 2)
                ];
            }
        }

        $this->style->newLine(1);
        $this->style->title('Starting the import of the documents');
        $pg = $this->style->createProgressBar(\count($documentArrays));
        $pg->start();
        foreach ($documentArrays as $document) {
            $this->bulker->indexDocument(Document::fromArray($document), $document['_index']);
            $pg->advance();
        }
        $pg->finish();

        try {
            $this->bulker->send(true);
        } catch (NoNodesAvailableException $e) {
            $this->logger->error('Bulker send failed.');
        }

    }

}
