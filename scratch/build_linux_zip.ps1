$projectRoot = 'c:\Projects\Waai_media'
$zipPath = Join-Path $projectRoot 'event-calendar-deploy-linux.zip'

if (Test-Path $zipPath) {
    Remove-Item -Force $zipPath
}

$files = @(
    'app/Http/Controllers/Api/EventController.php',
    'app/Http/Controllers/Api/Admin/EventController.php',
    'app/Http/Requests/Admin/Events/IndexEventRequest.php',
    'app/Http/Requests/Admin/Events/StoreEventRequest.php',
    'app/Http/Requests/Admin/Events/UpdateEventRequest.php',
    'app/Http/Requests/Admin/Events/PublishEventRequest.php',
    'app/Http/Requests/Events/IndexPublicEventRequest.php',
    'app/Http/Resources/EventAdminResource.php',
    'app/Http/Resources/EventPublicResource.php',
    'app/Models/Event.php',
    'app/Services/Events/EventCoverImageService.php',
    'app/Services/Events/EventDateTimeService.php',
    'app/Services/Events/EventSlugService.php',
    'database/factories/EventFactory.php',
    'database/migrations/2026_10_24_000001_create_events_table.php'
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

$archive.Dispose()
$zipStream.Dispose()

Write-Host "Successfully created $zipPath"
