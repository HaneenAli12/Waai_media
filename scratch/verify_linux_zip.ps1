$projectRoot = 'c:\Projects\Waai_media'
$zipPath = Join-Path $projectRoot 'event-calendar-deploy-linux.zip'

if (![System.IO.File]::Exists($zipPath)) {
    throw "Target zip does not exist: $zipPath"
}

$sha = [System.Security.Cryptography.SHA256]::Create()
$zipBytes = [System.IO.File]::ReadAllBytes($zipPath)
$zipHash = [BitConverter]::ToString($sha.ComputeHash($zipBytes)).Replace('-', '')

Write-Host "ZIP Path: $zipPath"
Write-Host "ZIP Size: $($zipBytes.Length) bytes"
Write-Host "ZIP SHA-256: $zipHash"

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zip = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
$entries = $zip.Entries

Write-Host "Total entries count: $($entries.Count)"

$expectedFiles = @(
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

$hasBackslash = $false
$hasTraversal = $false
$hasAbsolute = $false
$hasRootPrefix = $false
$allMatches = $true

Write-Host "`n--- INSPECTING ENTRIES & HASH VERIFICATION ---"

foreach ($entry in $entries) {
    $fullName = $entry.FullName
    $len = $entry.Length
    
    if ($fullName.Contains('\')) {
        $hasBackslash = $true
        Write-Host "ERROR: Entry contains backslash: $fullName"
    }
    if ($fullName.Contains('..')) {
        $hasTraversal = $true
        Write-Host "ERROR: Entry contains path traversal: $fullName"
    }
    if ($fullName.StartsWith('/') -or $fullName.Contains(':')) {
        $hasAbsolute = $true
        Write-Host "ERROR: Entry has absolute path: $fullName"
    }
    if ($fullName.StartsWith('Waai_media/')) {
        $hasRootPrefix = $true
        Write-Host "ERROR: Entry has Waai_media/ prefix: $fullName"
    }
    
    $localFile = Join-Path $projectRoot ($fullName.Replace('/', [System.IO.Path]::DirectorySeparatorChar))
    if (![System.IO.File]::Exists($localFile)) {
        Write-Host "ERROR: Local source file missing for entry: $fullName"
        $allMatches = $false
        continue
    }
    
    $localBytes = [System.IO.File]::ReadAllBytes($localFile)
    $localFileHash = [BitConverter]::ToString($sha.ComputeHash($localBytes)).Replace('-', '')
    
    $s = $entry.Open()
    $ms = New-Object System.IO.MemoryStream
    $s.CopyTo($ms)
    $s.Dispose()
    $entryBytes = $ms.ToArray()
    $ms.Dispose()
    $entryHash = [BitConverter]::ToString($sha.ComputeHash($entryBytes)).Replace('-', '')
    
    $isMatch = ($localFileHash -eq $entryHash)
    if (!$isMatch) {
        $allMatches = $false
    }
    
    Write-Host "ENTRY: $fullName | SIZE: $len | MATCH: $isMatch | HASH: $entryHash"
}

$zip.Dispose()

Write-Host "`n--- SUMMARY CHECKS ---"
Write-Host "Backslash check: $(if (!$hasBackslash) {'PASS (No backslashes)'} else {'FAIL'})"
Write-Host "Path traversal check: $(if (!$hasTraversal) {'PASS (No ..)'} else {'FAIL'})"
Write-Host "Absolute path check: $(if (!$hasAbsolute) {'PASS (No absolute)'} else {'FAIL'})"
Write-Host "Root prefix check: $(if (!$hasRootPrefix) {'PASS (No Waai_media/ prefix)'} else {'FAIL'})"
Write-Host "File count check: $(if ($entries.Count -eq 15) {'PASS (Exactly 15 files)'} else {'FAIL'})"
Write-Host "Hash comparison check: $(if ($allMatches) {'PASS (All 15 file hashes match)'} else {'FAIL'})"
