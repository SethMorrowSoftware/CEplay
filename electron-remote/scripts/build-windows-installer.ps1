<#
.SYNOPSIS
  Builds the CEplay Remote Windows installer on a Windows PC without requiring
  Git or a pre-existing Node/npm installation.

.DESCRIPTION
  Run this from an extracted copy of this repository, or pass -RepoZipUrl to
  download a repository ZIP. The script installs Node.js LTS if npm is missing,
  installs the Electron build dependencies, and runs electron-builder's Windows
  NSIS target. The installer is written to electron-remote\dist\.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\electron-remote\scripts\build-windows-installer.ps1

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\build-windows-installer.ps1 -RepoZipUrl "https://github.com/OWNER/REPO/archive/refs/heads/main.zip"
#>

[CmdletBinding()]
param(
  [string]$RepoZipUrl,
  [string]$SourceRoot,
  [string]$WorkDir = (Join-Path $env:TEMP 'ceplay-remote-build'),
  [switch]$Clean
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

function Write-Step {
  param([string]$Message)
  Write-Host "`n==> $Message" -ForegroundColor Cyan
}

function Test-Command {
  param([string]$Name)
  $null -ne (Get-Command $Name -ErrorAction SilentlyContinue)
}

function Refresh-Path {
  $machinePath = [Environment]::GetEnvironmentVariable('Path', 'Machine')
  $userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
  $env:Path = @($machinePath, $userPath) -join ';'
}

function Install-NodeWithWinget {
  if (-not (Test-Command 'winget')) { return $false }

  Write-Step 'Installing Node.js LTS with winget'
  winget install --id OpenJS.NodeJS.LTS --exact --silent --accept-package-agreements --accept-source-agreements
  Refresh-Path
  return (Test-Command 'npm')
}

function Install-NodeFromNodeJs {
  Write-Step 'Installing latest Node.js LTS MSI from nodejs.org'
  $index = Invoke-RestMethod -Uri 'https://nodejs.org/dist/index.json'
  $ltsRelease = $index |
    Where-Object { $_.lts -and ($_.files -contains 'win-x64-msi') } |
    Select-Object -First 1

  if (-not $ltsRelease) {
    throw 'Could not find a Node.js LTS win-x64 MSI in https://nodejs.org/dist/index.json.'
  }

  $version = $ltsRelease.version
  $msiUrl = "https://nodejs.org/dist/$version/node-$version-x64.msi"
  $msiPath = Join-Path $env:TEMP "node-$version-x64.msi"
  Invoke-WebRequest -Uri $msiUrl -OutFile $msiPath

  $proc = Start-Process msiexec.exe -ArgumentList @('/i', $msiPath, '/qn', '/norestart') -Wait -PassThru
  if ($proc.ExitCode -notin @(0, 3010)) {
    throw "Node.js MSI install failed with exit code $($proc.ExitCode)."
  }

  Refresh-Path
  if (-not (Test-Command 'npm')) {
    throw 'Node.js installed, but npm is still not on PATH. Open a new elevated PowerShell window and run this script again.'
  }
}

function Ensure-Node {
  if (Test-Command 'npm') {
    Write-Step 'Using existing Node/npm installation'
    node --version
    npm --version
    return
  }

  if (-not (Install-NodeWithWinget)) {
    Install-NodeFromNodeJs
  }

  Write-Step 'Node/npm installed'
  node --version
  npm --version
}

function Resolve-ElectronRemotePath {
  param([string]$Root)

  $candidates = @(
    $Root,
    (Join-Path $Root 'electron-remote')
  )

  foreach ($candidate in $candidates) {
    if (Test-Path (Join-Path $candidate 'package.json')) {
      $pkg = Get-Content (Join-Path $candidate 'package.json') -Raw | ConvertFrom-Json
      if ($pkg.name -eq 'ceplay-remote') {
        return (Resolve-Path $candidate).Path
      }
    }
  }

  $match = Get-ChildItem -Path $Root -Recurse -Filter package.json -File |
    Where-Object {
      try {
        $pkg = Get-Content $_.FullName -Raw | ConvertFrom-Json
        $pkg.name -eq 'ceplay-remote'
      } catch {
        $false
      }
    } |
    Select-Object -First 1

  if ($match) { return $match.Directory.FullName }
  throw "Could not find electron-remote\package.json below '$Root'."
}

function Prepare-Source {
  if ($SourceRoot) {
    return (Resolve-Path $SourceRoot).Path
  }

  if ($RepoZipUrl) {
    if ($Clean -and (Test-Path $WorkDir)) {
      Remove-Item -Path $WorkDir -Recurse -Force
    }
    New-Item -Path $WorkDir -ItemType Directory -Force | Out-Null

    $zipPath = Join-Path $WorkDir 'ceplay-repo.zip'
    $extractPath = Join-Path $WorkDir 'source'
    if (Test-Path $extractPath) { Remove-Item -Path $extractPath -Recurse -Force }

    Write-Step "Downloading repository ZIP from $RepoZipUrl"
    Invoke-WebRequest -Uri $RepoZipUrl -OutFile $zipPath
    Expand-Archive -Path $zipPath -DestinationPath $extractPath -Force
    return $extractPath
  }

  # Script lives in electron-remote\scripts; default to the repo root.
  $scriptDir = Split-Path -Parent $PSCommandPath
  return (Resolve-Path (Join-Path $scriptDir '..\..')).Path
}

if ($PSVersionTable.PSVersion.Major -ge 6 -and -not $IsWindows) {
  throw 'This script must be run on Windows because it builds a Windows installer.'
}

Write-Step 'Preparing CEplay Remote source'
$root = Prepare-Source
$appDir = Resolve-ElectronRemotePath -Root $root
Write-Host "Source: $appDir"

Ensure-Node

Write-Step 'Installing Electron build dependencies'
Push-Location $appDir
try {
  if (Test-Path 'package-lock.json') {
    npm ci
  } else {
    npm install
  }

  Write-Step 'Building Windows installer'
  npm run dist:win

  $installers = Get-ChildItem -Path (Join-Path $appDir 'dist') -Filter '*.exe' -File |
    Sort-Object LastWriteTime -Descending
  if (-not $installers) {
    throw "Build completed, but no .exe installer was found in $(Join-Path $appDir 'dist')."
  }

  Write-Step 'Installer ready'
  $installers | ForEach-Object { Write-Host $_.FullName -ForegroundColor Green }
} finally {
  Pop-Location
}
