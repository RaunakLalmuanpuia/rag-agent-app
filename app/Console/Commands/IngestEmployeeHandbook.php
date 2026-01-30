<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DocumentService;
use Illuminate\Support\Facades\Storage;

class IngestEmployeeHandbook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ingest:handbook';


    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ingest the Employee Handbook PDF into the vector database';

    /**
     * Execute the console command.
     */
    public function handle(DocumentService $service)
    {
        $this->info('Starting ingestion...');

        // Locate the file in storage/app/private
        $path = storage_path('app/private/Employee-Handbook.pdf');

        if (!file_exists($path)) {
            $this->error("File not found at: {$path}");
            return;
        }

        $document = $service->ingestPdf($path, 'Official Employee Handbook');

        $this->info("Success! Ingested: {$document->title}");
    }
}
