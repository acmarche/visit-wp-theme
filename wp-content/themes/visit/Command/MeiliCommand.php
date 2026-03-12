<?php

namespace VisitMarche\ThemeWp\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use VisitMarche\ThemeWp\Lib\Search\DataForSearch;
use VisitMarche\ThemeWp\Lib\Search\MeiliServer;

#[AsCommand(
    name: 'meili:server',
    description: ' ',
)]
class MeiliCommand extends Command
{
    private DataForSearch $dataForSearch;
    private readonly MeiliServer $meiliServer;

    protected function configure(): void
    {
        $this->setDescription('Manage server meilisearch');
        $this->addOption('key', "key", InputOption::VALUE_NONE, 'Create a key');
        $this->addOption('tasks', "tasks", InputOption::VALUE_NONE, 'Display tasks');
        $this->addOption('reset', "reset", InputOption::VALUE_NONE, 'Search engine reset');
        $this->addOption('update', "update", InputOption::VALUE_NONE, 'Update data');
        $this->addOption('dump', "dump", InputOption::VALUE_NONE, 'migrate data');
        $this->addOption('translate', "translate", InputOption::VALUE_NONE, 'Translate offer names and excerpts via OpenAI');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = (bool)$input->getOption('key');
        $tasks = (bool)$input->getOption('tasks');
        $reset = (bool)$input->getOption('reset');
        $update = (bool)$input->getOption('update');
        $dump = (bool)$input->getOption('dump');
        $translate = (bool)$input->getOption('translate');

        $this->meiliServer = new MeiliServer();
        $this->meiliServer->initClientAndIndex();

        if ($key) {
            dump($this->meiliServer->createApiKey());

            return Command::SUCCESS;
        }

        if ($tasks) {
            $this->tasks($output);

            return Command::SUCCESS;
        }

        if ($reset) {
            $result = $this->meiliServer->createIndex();
            dump($result);
            $result = $this->meiliServer->settings();
            dump($result);

            return Command::SUCCESS;
        }

        if ($update) {
            $this->dataForSearch = new DataForSearch();

            $output->writeln('<info>Indexing posts...</info>');
            $this->indexPosts($output);
            $this->freeMemory();

            $output->writeln('<info>Indexing categories...</info>');
            $this->indexCategories($output);
            $this->freeMemory();

            $output->writeln('<info>Indexing offers...</info>');
            $this->indexOffers($output, $translate);
            $this->freeMemory();

            $output->writeln('<comment>Indexation complete!</comment>');

            return Command::SUCCESS;
        }

        if ($dump) {
            dump($this->meiliServer->dump());
        }

        return Command::SUCCESS;
    }

    private function indexPosts(OutputInterface $output): void
    {
        $documents = [];
        $posts = $this->dataForSearch->getPosts();
        $output->writeln(sprintf(' %d posts', count($posts)));
        foreach ($posts as $document) {
            $documents[] = $document;
        }
        unset($posts);
        $this->freeMemory();

        $this->indexInBatches($documents, $output);
    }

    private function indexCategories(OutputInterface $output): void
    {
        $documents = [];

        $categories = $this->dataForSearch->getCategories();
        $output->writeln(sprintf(' %d categories', count($categories)));
        foreach ($categories as $document) {
            $documents[] = $document;
        }
        unset($categories);
        $this->freeMemory();

        $this->indexInBatches($documents, $output);
    }

    private function indexOffers(OutputInterface $output, bool $translate = false): void
    {
        $documents = [];

        if ($translate) {
            $output->writeln('<comment> Translations enabled (OpenAI) — cached results will be reused</comment>');
        }

        $offers = $this->dataForSearch->getOffers(translate: $translate);
        $output->writeln(sprintf(' %d offers', count($offers)));
        foreach ($offers as $document) {
            $documents[] = $document;
        }
        unset($offers);
        $this->freeMemory();

        $this->indexInBatches($documents, $output);
    }

    private function indexInBatches(array $documents, OutputInterface $output, int $batchSize = 500): void
    {
        $chunks = array_chunk($documents, $batchSize);
        foreach ($chunks as $i => $batch) {
            $this->meiliServer->index->addDocuments($batch, $this->meiliServer->primaryKey);
        }
        unset($documents, $chunks);
    }

    private function freeMemory(): void
    {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }

    private function tasks(OutputInterface $output): void
    {
        $tasks = $this->meiliServer->client->getTasks();
        $data = [];
        foreach ($tasks->getResults() as $result) {
            $t = [$result['uid'], $result['status'], $result['type'], $result['startedAt']];
            $t['error'] = null;
            $t['url'] = null;
            if ($result['status'] == 'failed') {
                if (isset($result['error'])) {
                    $t['error'] = $result['error']['message'];
                    $t['link'] = $result['error']['link'];
                }
            }
            $data[] = $t;
        }
        $table = new Table($output);
        $table
            ->setHeaders(['Uid', 'status', 'Type', 'Date', 'Error', 'Url'])
            ->setRows($data);
        $table->render();
    }
}
