$projectRoot = 'c:\Projects\Waai_media'
$zipPath = 'C:\Projects\event-calendar-production-package.zip'

if (Test-Path $zipPath) {
    Remove-Item -Force $zipPath
}

$files = @(
    'app/Models/Event.php',
    'app/Http/Controllers/Api/EventController.php',
    'app/Http/Controllers/Api/Admin/EventController.php',
    'app/Http/Requests/Events/IndexPublicEventRequest.php',
    'app/Http/Requests/Admin/Events/IndexEventRequest.php',
    'app/Http/Requests/Admin/Events/StoreEventRequest.php',
    'app/Http/Requests/Admin/Events/UpdateEventRequest.php',
    'app/Http/Requests/Admin/Events/PublishEventRequest.php',
    'app/Http/Resources/EventPublicResource.php',
    'app/Http/Resources/EventAdminResource.php',
    'app/Services/Events/EventSlugService.php',
    'app/Services/Events/EventCoverImageService.php',
    'app/Services/Events/EventDateTimeService.php',
    'app/Support/LocaleNegotiator.php',
    'database/factories/EventFactory.php',
    'database/migrations/2026_10_24_000001_create_events_table.php',
    'routes/api.php'
)

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zipStream = [System.IO.File]::Open($zipPath, [System.IO.FileMode]::CreateNew)
$archive = New-Object System.IO.Compression.ZipArchive($zipStream, [System.IO.Compression.ZipArchiveMode]::Create)

foreach ($relPath in $files) {
    $fullPath = Join-Path $projectRoot ($relPath.Replace('/', [System.IO.Path]::DirectorySeparatorChar))
    if (![System.IO.File]::Exists($fullPath)) {
        throw "Source file missing: $fullPath"
    }
    
    # Strictly ensure forward slash
    $entryName = $relPath.Replace('\', '/')
    $entry = $archive.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)
    
    $entryStream = $entry.Open()
    $fileStream = [System.IO.File]::OpenRead($fullPath)
    $fileStream.CopyTo($entryStream)
    $fileStream.Dispose()
    $entryStream.Dispose()
}

$readmeContent = @"
# Event Calendar — Production Deployment Instructions
======================================================
Package: event-calendar-production-package.zip
Target: Waai Media Laravel Application Root
Date: 2026-10-24

## 1. Package File Manifest (17 Application Files)
--------------------------------------------------
app/Models/Event.php
app/Http/Controllers/Api/EventController.php
app/Http/Controllers/Api/Admin/EventController.php
app/Http/Requests/Events/IndexPublicEventRequest.php
app/Http/Requests/Admin/Events/IndexEventRequest.php
app/Http/Requests/Admin/Events/StoreEventRequest.php
app/Http/Requests/Admin/Events/UpdateEventRequest.php
app/Http/Requests/Admin/Events/PublishEventRequest.php
app/Http/Resources/EventPublicResource.php
app/Http/Resources/EventAdminResource.php
app/Services/Events/EventSlugService.php
app/Services/Events/EventCoverImageService.php
app/Services/Events/EventDateTimeService.php
app/Support/LocaleNegotiator.php
database/factories/EventFactory.php
database/migrations/2026_10_24_000001_create_events_table.php
routes/api.php

## 2. Pre-Deployment Verification on Production Database (Hostinger)
--------------------------------------------------------------------
Before running migrations, execute the following read-only query in MySQL:
SELECT id, migration, batch FROM migrations WHERE migration LIKE '%events%';
SHOW TABLES LIKE 'event%';

A. If 'event_calendar' does NOT exist and no migration record exists:
   Run: php artisan migrate --force

B. If 'events' table exists from a previous trial:
   Run: RENAME TABLE events TO event_calendar;

## 3. Post-Deployment Cache Clearing
------------------------------------
After unpacking the files into the Laravel root directory:
php artisan route:clear
php artisan config:clear
php artisan cache:clear
"@

$readmeEntry = $archive.CreateEntry('DEPLOYMENT_INSTRUCTIONS.txt', [System.IO.Compression.CompressionLevel]::Optimal)
$readmeStream = $readmeEntry.Open()
$writer = New-Object System.IO.StreamWriter($readmeStream, [System.Text.Encoding]::UTF8)
$writer.Write($readmeContent)
$writer.Dispose()
$readmeStream.Dispose()

$archive.Dispose()
$zipStream.Dispose()

Write-Host "Successfully created $zipPath"

