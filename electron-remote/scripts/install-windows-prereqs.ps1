<#
.SYNOPSIS
  Installs the Windows tools commonly needed to clone and build CEplay Remote.

.DESCRIPTION
  Installs Git for Windows and Node.js LTS (which includes npm). Prefer winget
  when available, and fall back to the official installers downloaded from
  git-scm.com and nodejs.org. Run from an elevated PowerShell window.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\electron-remote\scripts\install-windows-prereqs.ps1
#>

[CmdletBinding()]
param()

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

function Install-WithWinget {
  param(
    [string]$Id,
    [string]$Name
  )

  if (-not (Test-Command 'winget')) { return $false }

  Write-Step "Installing $Name with winget"
  winget install --id $Id --exact --silent --accept-package-agreements --accept-source-agreements
  Refresh-Path
  return $true
}

function Install-GitFallback {
  Write-Step 'Installing Git for Windows from git-scm.com'
  $release = Invoke-RestMethod -Uri 'https://api.github.com/repos/git-for-windows/git/releases/latest'
  $asset = $release.assets |
    Where-Object { $_.name -match '^Git-[0-9].*-64-bit\.exe$' } |
    Select-Object -First 1

  if (-not $asset) {
    throw 'Could not find the latest 64-bit Git for Windows installer.'
  }

  $installerPath = Join-Path $env:TEMP $asset.name
  Invoke-WebRequest -Uri $asset.browser_download_url -OutFile $installerPath
  $proc = Start-Process $installerPath -ArgumentList @('/VERYSILENT', '/NORESTART', '/NOCANCEL') -Wait -PassThru
  if ($proc.ExitCode -ne 0) {
    throw "Git installer failed with exit code $($proc.ExitCode)."
  }
  Refresh-Path
}

function Install-NodeFallback {
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
}

if ($PSVersionTable.PSVersion.Major -ge 6 -and -not $IsWindows) {
  throw 'This script must be run on Windows.'
}

if (Test-Command 'git') {
  Write-Step 'Git is already installed'
  git --version
} else {
  if (-not (Install-WithWinget -Id 'Git.Git' -Name 'Git for Windows')) {
    Install-GitFallback
  }
  if (-not (Test-Command 'git')) {
    throw 'Git was installed, but git.exe is not on PATH. Open a new elevated PowerShell window and run this script again.'
  }
  git --version
}

if (Test-Command 'npm') {
  Write-Step 'Node.js/npm are already installed'
  node --version
  npm --version
} else {
  if (-not (Install-WithWinget -Id 'OpenJS.NodeJS.LTS' -Name 'Node.js LTS')) {
    Install-NodeFallback
  }
  if (-not (Test-Command 'npm')) {
    throw 'Node.js was installed, but npm is not on PATH. Open a new elevated PowerShell window and run this script again.'
  }
  node --version
  npm --version
}

Write-Step 'Ready'
Write-Host 'If any command was installed just now, open a NEW PowerShell window before cloning/building so PATH is fully refreshed.' -ForegroundColor Yellow
