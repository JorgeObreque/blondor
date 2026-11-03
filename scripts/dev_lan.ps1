# Abre el ambiente local (Docker en WSL) a otros equipos de la red de la casa, por ejemplo el celular:
#   sitio  http://<IP del PC>:8087
#   agenda http://<IP del PC>:8088
# WSL corre en modo NAT, así que Windows solo publica esos puertos en localhost. Este script crea el reenvío
# (portproxy) hacia la IP actual de WSL y una regla de firewall que solo acepta equipos de la misma subred.
#
# Ejecutar en PowerShell como administrador (la IP de WSL cambia al reiniciar WSL: volver a ejecutarlo):
#   powershell -ExecutionPolicy Bypass -File \\wsl.localhost\Ubuntu\home\jorge\Projects\blondor\scripts\dev_lan.ps1
# Para cerrarlo:
#   ... dev_lan.ps1 -Off

param([switch]$Off)

$ports = @(8087, 8088)
$rule = 'Blondor ambiente local'

foreach ($port in $ports) {
    netsh interface portproxy delete v4tov4 listenaddress=0.0.0.0 listenport=$port | Out-Null
}
Remove-NetFirewallRule -DisplayName $rule -ErrorAction SilentlyContinue

if ($Off) {
    Write-Host 'Ambiente local cerrado a la red.'
    exit 0
}

$wsl = ((wsl.exe hostname -I) -split '\s+' | Where-Object { $_ -match '^\d+\.\d+\.\d+\.\d+$' } | Select-Object -First 1)
if (-not $wsl) {
    Write-Error 'No se pudo obtener la IP de WSL. ¿Está WSL encendido?'
    exit 1
}

foreach ($port in $ports) {
    netsh interface portproxy add v4tov4 listenaddress=0.0.0.0 listenport=$port connectaddress=$wsl connectport=$port | Out-Null
}
# La red del PC está marcada como Pública en Windows: se acepta cualquier perfil, pero solo desde la misma subred.
New-NetFirewallRule -DisplayName $rule -Direction Inbound -Protocol TCP -LocalPort $ports -Action Allow -Profile Any -RemoteAddress LocalSubnet | Out-Null

$lan = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -like '192.168.*' } | Select-Object -First 1).IPAddress
Write-Host "WSL: $wsl"
Write-Host "Sitio:  http://${lan}:8087"
Write-Host "Agenda: http://${lan}:8088"
