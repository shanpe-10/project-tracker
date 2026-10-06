param([ValidateSet('Open','Start','Stop','Restart','Status')][string]$Action='Open')
$ErrorActionPreference='Stop'
$serviceName='LGUProjectTrackerApache'
$url='http://localhost/project-tracker/public/'
$principal=[Security.Principal.WindowsPrincipal]::new([Security.Principal.WindowsIdentity]::GetCurrent())
try {
    if($Action -eq 'Status'){
        Get-Service $serviceName,'postgresql-x64-18' | Select-Object Name,Status,StartType
        exit 0
    }
    $servicesReady=(Get-Service $serviceName).Status -eq 'Running' -and (Get-Service 'postgresql-x64-18').Status -eq 'Running'
    if($Action -eq 'Open' -and $servicesReady){Start-Process $url;exit 0}
    $operation=if($Action -eq 'Open'){'Start'}else{$Action}
    if(!$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){
        $arguments='-NoProfile -ExecutionPolicy Bypass -File "'+$PSCommandPath+'" -Action '+$operation
        $helper=Start-Process -FilePath "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -ArgumentList $arguments -Verb RunAs -WindowStyle Hidden -PassThru -Wait
        if($helper.ExitCode){throw 'The server operation failed. Review runtime/apache/logs/error.log and the PostgreSQL log.'}
    }else{
        if($operation -eq 'Stop'){Stop-Service $serviceName}
        else{
            if((Get-Service 'postgresql-x64-18').Status -ne 'Running'){Start-Service 'postgresql-x64-18'}
            (Get-Service 'postgresql-x64-18').WaitForStatus('Running',[TimeSpan]::FromSeconds(45))
            if($operation -eq 'Restart'){Restart-Service $serviceName}else{Start-Service $serviceName}
            (Get-Service $serviceName).WaitForStatus('Running',[TimeSpan]::FromSeconds(30))
            if((Invoke-WebRequest -Uri $url -UseBasicParsing).StatusCode -ne 200){throw 'The tracker did not respond.'}
        }
    }
    if($Action -eq 'Open'){Start-Process $url}
}catch{
    $_.Exception.Message | Out-File -LiteralPath (Join-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) 'runtime\logs\server-control-error.txt') -Encoding UTF8
    Write-Error $_.Exception.Message
    exit 1
}
