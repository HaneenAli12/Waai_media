Add-Type -AssemblyName System.IO.Compression.FileSystem

$zipPath = "C:\Projects\Waai_media\event-calendar-deploy.zip"
if (-not (Test-Path $zipPath)) {
    Write-Output "ZIP_EXISTS: FALSE"
    exit 1
}

$zip = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
$zipHash = (Get-FileHash -Path $zipPath -Algorithm SHA256).Hash
$zipSize = (Get-Item $zipPath).Length

Write-Output "ZIP_EXISTS: TRUE"
Write-Output "ZIP_PATH: $zipPath"
Write-Output "ZIP_SIZE: $zipSize"
Write-Output "ZIP_HASH: $zipHash"
Write-Output "ZIP_ENTRY_COUNT: $($zip.Entries.Count)"
Write-Output "--- ENTRIES ---"

foreach ($entry in $zip.Entries) {
    Write-Output ("ENTRY: {0} | SIZE: {1}" -f $entry.FullName, $entry.Length)
}

$zip.Dispose()
