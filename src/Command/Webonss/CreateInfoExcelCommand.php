<?php

declare(strict_types=1);

namespace App\Command\Webonss;

use EMS\CommonBundle\Command\CommandInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

class CreateInfoExcelCommand extends Command implements CommandInterface
{
    /** @var SymfonyStyle */
    private $style;
    /** @var Finder */
    private $finder;

    private const ARG_PATH = 'path';

    protected static $defaultName = 'ems:import:webonss:info-excel';

    protected function configure(): void
    {
        $this->addArgument(self::ARG_PATH, InputArgument::REQUIRED);
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->style = new SymfonyStyle($input, $output);
        $this->finder = Finder::create()->in($input->getArgument(self::ARG_PATH))->files();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->style->title('Create info excel');

        $csv = fopen('file.csv', 'w');
        fputcsv($csv, ['filename', 'type', 'location', 'year', 'quarter']);

        $fileNameRegex = '/(?\'year\'(1|2)\d{3})(?\'quarter\'\d{1})?/';

        foreach ($this->finder as $file) {
            $fileName = $file->getFilename();
            $infoFileName = [];
            preg_match($fileNameRegex, $fileName, $infoFileName);

            fputcsv($csv, [
                $fileName,
                $file->getExtension(),
                $file->getRelativePath(),
                $infoFileName['year'] ?? null,
                $infoFileName['quarter'] ?? null,
            ]);
        }

        fclose($csv);

        return 1;
    }
}