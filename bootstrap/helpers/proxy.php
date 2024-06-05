<?php

use App\Actions\Proxy\SaveConfiguration;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Server;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;


function connectProxyToNetworks(Server $server)
{
    if ($server->isSwarm()) {
        $networks = collect($server->swarmDockers)->map(function ($docker) {
            return $docker['network'];
        });
    } else {
        // Standalone networks
        $networks = collect($server->standaloneDockers)->map(function ($docker) {
            return $docker['network'];
        });
    }
    // Service networks
    foreach ($server->services()->get() as $service) {
        $networks->push($service->networks());
    }
    // Docker compose based apps
    $docker_compose_apps = $server->dockerComposeBasedApplications();
    foreach ($docker_compose_apps as $app) {
        $networks->push($app->uuid);
    }
    // Docker compose based preview deployments
    $docker_compose_previews = $server->dockerComposeBasedPreviewDeployments();
    foreach ($docker_compose_previews as $preview) {
        $pullRequestId = $preview->pull_request_id;
        $applicationId = $preview->application_id;
        $application = Application::find($applicationId);
        if (!$application) {
            continue;
        }
        $network = "{$application->uuid}-{$pullRequestId}";
        $networks->push($network);
    }
    $networks = collect($networks)->flatten()->unique();
    if ($server->isSwarm()) {
        if ($networks->count() === 0) {
            $networks = collect(['coolify-overlay']);
        }
        $commands = $networks->map(function ($network) {
            return [
                "echo 'Connecting coolify-proxy to $network network...'",
                "docker network ls --format '{{.Name}}' | grep '^$network$' >/dev/null || docker network create --driver overlay --attachable $network >/dev/null",
                "docker network connect $network coolify-proxy >/dev/null 2>&1 || true",
            ];
        });
    } else {
        if ($networks->count() === 0) {
            $networks = collect(['coolify']);
        }
        $commands = $networks->map(function ($network) {
            return [
                "echo 'Connecting coolify-proxy to $network network...'",
                "docker network ls --format '{{.Name}}' | grep '^$network$' >/dev/null || docker network create --attachable $network >/dev/null",
                "docker network connect $network coolify-proxy >/dev/null 2>&1 || true",
            ];
        });
    }

    return $commands->flatten();
}
function generate_default_proxy_configuration(Server $server)
{
    $proxy_path = $server->proxyPath();
    $proxy_type = $server->proxyType();

    if ($server->isSwarm()) {
        $networks = collect($server->swarmDockers)->map(function ($docker) {
            return $docker['network'];
        })->unique();
        if ($networks->count() === 0) {
            $networks = collect(['coolify-overlay']);
        }
    } else {
        $networks = collect($server->standaloneDockers)->map(function ($docker) {
            return $docker['network'];
        })->unique();
        if ($networks->count() === 0) {
            $networks = collect(['coolify']);
        }
    }

    $array_of_networks = collect([]);
    $networks->map(function ($network) use ($array_of_networks) {
        $array_of_networks[$network] = [
            "external" => true,
        ];
    });
    if ($proxy_type === 'TRAEFIK_V2') {
        $labels = [
            "traefik.enable=true",
            "traefik.http.routers.traefik.entrypoints=http",
            "traefik.http.routers.traefik.service=api@internal",
            "traefik.http.services.traefik.loadbalancer.server.port=8080",
            "coolify.managed=true",
        ];
        $config = [
            "version" => "3.8",
            "networks" => $array_of_networks->toArray(),
            "services" => [
                "traefik" => [
                    "container_name" => "coolify-proxy",
                    "image" => "traefik:v2.10",
                    "restart" => RESTART_MODE,
                    "extra_hosts" => [
                        "host.docker.internal:host-gateway",
                    ],
                    "networks" => $networks->toArray(),
                    "ports" => [
                        "80:80",
                        "443:443",
                        "8080:8080",
                    ],
                    "healthcheck" => [
                        "test" => "wget -qO- http://localhost:80/ping || exit 1",
                        "interval" => "4s",
                        "timeout" => "2s",
                        "retries" => 5,
                    ],
                    "volumes" => [
                        "/var/run/docker.sock:/var/run/docker.sock:ro",
                        "{$proxy_path}:/traefik",
                    ],
                    "command" => [
                        "--ping=true",
                        "--ping.entrypoint=http",
                        "--api.dashboard=true",
                        "--api.insecure=false",
                        "--entrypoints.http.address=:80",
                        "--entrypoints.https.address=:443",
                        "--entrypoints.http.http.encodequerysemicolons=true",
                        "--entryPoints.http.http2.maxConcurrentStreams=50",
                        "--entrypoints.https.http.encodequerysemicolons=true",
                        "--entryPoints.https.http2.maxConcurrentStreams=50",
                        "--providers.docker.exposedbydefault=false",
                        "--providers.file.directory=/traefik/dynamic/",
                        "--providers.file.watch=true",
                        "--certificatesresolvers.letsencrypt.acme.httpchallenge=true",
                        "--certificatesresolvers.letsencrypt.acme.storage=/traefik/acme.json",
                        "--certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=http",
                    ],
                    "labels" => $labels,
                ],
            ],
        ];
        if (isDev()) {
            // $config['services']['traefik']['command'][] = "--log.level=debug";
            $config['services']['traefik']['command'][] = "--accesslog.filepath=/traefik/access.log";
            $config['services']['traefik']['command'][] = "--accesslog.bufferingsize=100";
        }
        if ($server->isSwarm()) {
            data_forget($config, 'services.traefik.container_name');
            data_forget($config, 'services.traefik.restart');
            data_forget($config, 'services.traefik.labels');

            $config['services']['traefik']['command'][] = "--providers.docker.swarmMode=true";
            $config['services']['traefik']['deploy'] = [
                "labels" => $labels,
                "placement" => [
                    "constraints" => [
                        "node.role==manager",
                    ],
                ],
            ];
        } else {
            $config['services']['traefik']['command'][] = "--providers.docker=true";
        }
    } else if ($proxy_type === 'CADDY') {
        $config = [
            "version" => "3.8",
            "networks" => $array_of_networks->toArray(),
            "services" => [
                "caddy" => [
                    "container_name" => "coolify-proxy",
                    "image" => "lucaslorentz/caddy-docker-proxy:2.8-alpine",
                    "restart" => RESTART_MODE,
                    "extra_hosts" => [
                        "host.docker.internal:host-gateway",
                    ],
                    "environment" => [
                        "CADDY_DOCKER_POLLING_INTERVAL=5s",
                        "CADDY_DOCKER_CADDYFILE_PATH=/dynamic/Caddyfile",
                    ],
                    "networks" => $networks->toArray(),
                    "ports" => [
                        "80:80",
                        "443:443",
                    ],
                    // "healthcheck" => [
                    //     "test" => "wget -qO- http://localhost:80|| exit 1",
                    //     "interval" => "4s",
                    //     "timeout" => "2s",
                    //     "retries" => 5,
                    // ],
                    "volumes" => [
                        "/var/run/docker.sock:/var/run/docker.sock:ro",
                        "{$proxy_path}/dynamic:/dynamic",
                        "{$proxy_path}/config:/config",
                        "{$proxy_path}/data:/data",
                    ],
                ],
            ],
        ];
    } else {
        return null;
    }

    $config = Yaml::dump($config, 12, 2);
    SaveConfiguration::run($server, $config);
    return $config;
}
