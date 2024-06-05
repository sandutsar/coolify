<?php

use App\Jobs\ServerFilesFromServerJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Internal\GeneralNotification;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Process\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Token\Builder;
use phpseclib3\Crypt\EC;
use Poliander\Cron\CronExpression;
use Visus\Cuid2\Cuid2;
use phpseclib3\Crypt\RSA;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;
use PurplePixie\PhpDns\DNSQuery;

function base_configuration_dir(): string
{
    return '/data/coolify';
}
function application_configuration_dir(): string
{
    return base_configuration_dir() . "/applications";
}
function service_configuration_dir(): string
{
    return base_configuration_dir() . "/services";
}
function database_configuration_dir(): string
{
    return base_configuration_dir() . "/databases";
}
function database_proxy_dir($uuid): string
{
    return base_configuration_dir() . "/databases/$uuid/proxy";
}
function backup_dir(): string
{
    return base_configuration_dir() . "/backups";
}

function generate_readme_file(string $name, string $updated_at): string
{
    return "Resource name: $name\nLatest Deployment Date: $updated_at";
}

function isInstanceAdmin()
{
    return auth()?->user()?->isInstanceAdmin() ?? false;
}

function currentTeam()
{
    return auth()?->user()?->currentTeam() ?? null;
}

function showBoarding(): bool
{
    if (auth()->user()?->isMember()) {
        return false;
    }
    return currentTeam()->show_boarding ?? false;
}
function refreshSession(?Team $team = null): void
{
    if (!$team) {
        if (auth()->user()?->currentTeam()) {
            $team = Team::find(auth()->user()->currentTeam()->id);
        } else {
            $team = User::find(auth()->user()->id)->teams->first();
        }
    }
    Cache::forget('team:' . auth()->user()->id);
    Cache::remember('team:' . auth()->user()->id, 3600, function () use ($team) {
        return $team;
    });
    session(['currentTeam' => $team]);
}
function handleError(?Throwable $error = null, ?Livewire\Component $livewire = null, ?string $customErrorMessage = null)
{
    ray($error);
    if ($error instanceof TooManyRequestsException) {
        if (isset($livewire)) {
            return $livewire->dispatch('error', "Too many requests. Please try again in {$error->secondsUntilAvailable} seconds.");
        }
        return "Too many requests. Please try again in {$error->secondsUntilAvailable} seconds.";
    }
    if ($error instanceof UniqueConstraintViolationException) {
        if (isset($livewire)) {
            return $livewire->dispatch('error', 'Duplicate entry found. Please use a different name.');
        }
        return "Duplicate entry found. Please use a different name.";
    }

    if ($error instanceof Throwable) {
        $message = $error->getMessage();
    } else {
        $message = null;
    }
    if ($customErrorMessage) {
        $message = $customErrorMessage . ' ' . $message;
    }

    if (isset($livewire)) {
        return $livewire->dispatch('error', $message);
    }
    throw new Exception($message);
}
function get_route_parameters(): array
{
    return Route::current()->parameters();
}

function get_latest_sentinel_version(): string
{
    try {
        $response = Http::get('https://cdn.coollabs.io/coolify/versions.json');
        $versions = $response->json();
        return data_get($versions, 'coolify.sentinel.version');
    } catch (\Throwable $e) {
        //throw $e;
        ray($e->getMessage());
        return '0.0.0';
    }
}
function get_latest_version_of_coolify(): string
{
    try {
        $versions = File::get(base_path('versions.json'));
        $versions = json_decode($versions, true);
        return data_get($versions, 'coolify.v4.version');
        // $response = Http::get('https://cdn.coollabs.io/coolify/versions.json');
        // $versions = $response->json();
        // return data_get($versions, 'coolify.v4.version');
    } catch (\Throwable $e) {
        //throw $e;
        ray($e->getMessage());
        return '0.0.0';
    }
}

function generate_random_name(?string $cuid = null): string
{
    $generator = new \Nubs\RandomNameGenerator\All(
        [
            new \Nubs\RandomNameGenerator\Alliteration(),
        ]
    );
    if (is_null($cuid)) {
        $cuid = new Cuid2(7);
    }
    return Str::kebab("{$generator->getName()}-$cuid");
}
function generateSSHKey(string $type = 'rsa')
{
    if ($type === 'rsa') {
        $key = RSA::createKey();
        return [
            'private' => $key->toString('PKCS1'),
            'public' => $key->getPublicKey()->toString('OpenSSH', ['comment' => 'coolify-generated-ssh-key'])
        ];
    } else if ($type === 'ed25519') {
        $key = EC::createKey('Ed25519');
        return [
            'private' => $key->toString('OpenSSH'),
            'public' => $key->getPublicKey()->toString('OpenSSH', ['comment' => 'coolify-generated-ssh-key'])
        ];
    }
    throw new Exception('Invalid key type');
}
function formatPrivateKey(string $privateKey)
{
    $privateKey = trim($privateKey);
    if (!str_ends_with($privateKey, "\n")) {
        $privateKey .= "\n";
    }
    return $privateKey;
}
function generate_application_name(string $git_repository, string $git_branch, ?string $cuid = null): string
{
    if (is_null($cuid)) {
        $cuid = new Cuid2(7);
    }
    return Str::kebab("$git_repository:$git_branch-$cuid");
}

function is_transactional_emails_active(): bool
{
    return isEmailEnabled(InstanceSettings::get());
}

function set_transanctional_email_settings(InstanceSettings | null $settings = null): string|null
{
    if (!$settings) {
        $settings = InstanceSettings::get();
    }
    config()->set('mail.from.address', data_get($settings, 'smtp_from_address'));
    config()->set('mail.from.name', data_get($settings, 'smtp_from_name'));
    if (data_get($settings, 'resend_enabled')) {
        config()->set('mail.default', 'resend');
        config()->set('resend.api_key', data_get($settings, 'resend_api_key'));
        return 'resend';
    }
    if (data_get($settings, 'smtp_enabled')) {
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp', [
            "transport" => "smtp",
            "host" => data_get($settings, 'smtp_host'),
            "port" => data_get($settings, 'smtp_port'),
            "encryption" => data_get($settings, 'smtp_encryption'),
            "username" => data_get($settings, 'smtp_username'),
            "password" => data_get($settings, 'smtp_password'),
            "timeout" => data_get($settings, 'smtp_timeout'),
            "local_domain" => null,
        ]);
        return 'smtp';
    }
    return null;
}

function base_ip(): string
{
    if (isDev()) {
        return "localhost";
    }
    $settings = InstanceSettings::get();
    if ($settings->public_ipv4) {
        return "$settings->public_ipv4";
    }
    if ($settings->public_ipv6) {
        return "$settings->public_ipv6";
    }
    return "localhost";
}
function getFqdnWithoutPort(String $fqdn)
{
    try {
        $url = Url::fromString($fqdn);
        $host = $url->getHost();
        $scheme = $url->getScheme();
        $path = $url->getPath();
        return "$scheme://$host$path";
    } catch (\Throwable $e) {
        return $fqdn;
    }
}
/**
 * If fqdn is set, return it, otherwise return public ip.
 */
function base_url(bool $withPort = true): string
{
    $settings = InstanceSettings::get();
    if ($settings->fqdn) {
        return $settings->fqdn;
    }
    $port = config('app.port');
    if ($settings->public_ipv4) {
        if ($withPort) {
            if (isDev()) {
                return "http://localhost:$port";
            }
            return "http://$settings->public_ipv4:$port";
        }
        if (isDev()) {
            return "http://localhost";
        }
        return "http://$settings->public_ipv4";
    }
    if ($settings->public_ipv6) {
        if ($withPort) {
            return "http://$settings->public_ipv6:$port";
        }
        return "http://$settings->public_ipv6";
    }
    return url('/');
}

function isSubscribed()
{
    return isSubscriptionActive() || auth()->user()->isInstanceAdmin();
}
function isDev(): bool
{
    return config('app.env') === 'local';
}

function isCloud(): bool
{
    return !config('coolify.self_hosted');
}

function validate_cron_expression($expression_to_validate): bool
{
    $isValid = false;
    $expression = new CronExpression($expression_to_validate);
    $isValid = $expression->isValid();

    if (isset(VALID_CRON_STRINGS[$expression_to_validate])) {
        $isValid = true;
    }
    return $isValid;
}
function send_internal_notification(string $message): void
{
    try {
        $team = Team::find(0);
        $team?->notify(new GeneralNotification($message));
    } catch (\Throwable $e) {
        ray($e->getMessage());
    }
}
function send_user_an_email(MailMessage $mail, string $email, ?string $cc = null): void
{
    $settings = InstanceSettings::get();
    $type = set_transanctional_email_settings($settings);
    if (!$type) {
        throw new Exception('No email settings found.');
    }
    if ($cc) {
        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->to($email)
                ->replyTo($email)
                ->cc($cc)
                ->subject($mail->subject)
                ->html((string) $mail->render())
        );
    } else {
        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->to($email)
                ->subject($mail->subject)
                ->html((string) $mail->render())
        );
    }
}
function isTestEmailEnabled($notifiable)
{
    if (data_get($notifiable, 'use_instance_email_settings') && isInstanceAdmin()) {
        return true;
    } else if (data_get($notifiable, 'smtp_enabled') || data_get($notifiable, 'resend_enabled') && auth()->user()->isAdminFromSession()) {
        return true;
    }
    return false;
}
function isEmailEnabled($notifiable)
{
    return data_get($notifiable, 'smtp_enabled') || data_get($notifiable, 'resend_enabled') || data_get($notifiable, 'use_instance_email_settings');
}
function setNotificationChannels($notifiable, $event)
{
    $channels = [];
    $isEmailEnabled = isEmailEnabled($notifiable);
    $isDiscordEnabled = data_get($notifiable, 'discord_enabled');
    $isTelegramEnabled = data_get($notifiable, 'telegram_enabled');
    $isSubscribedToEmailEvent = data_get($notifiable, "smtp_notifications_$event");
    $isSubscribedToDiscordEvent = data_get($notifiable, "discord_notifications_$event");
    $isSubscribedToTelegramEvent = data_get($notifiable, "telegram_notifications_$event");

    if ($isDiscordEnabled && $isSubscribedToDiscordEvent) {
        $channels[] = DiscordChannel::class;
    }
    if ($isEmailEnabled && $isSubscribedToEmailEvent) {
        $channels[] = EmailChannel::class;
    }
    if ($isTelegramEnabled && $isSubscribedToTelegramEvent) {
        $channels[] = TelegramChannel::class;
    }
    return $channels;
}
function parseEnvFormatToArray($env_file_contents)
{
    $env_array = array();
    $lines = explode("\n", $env_file_contents);
    foreach ($lines as $line) {
        if ($line === '' || substr($line, 0, 1) === '#') {
            continue;
        }
        $equals_pos = strpos($line, '=');
        if ($equals_pos !== false) {
            $key = substr($line, 0, $equals_pos);
            $value = substr($line, $equals_pos + 1);
            if (substr($value, 0, 1) === '"' && substr($value, -1) === '"') {
                $value = substr($value, 1, -1);
            } elseif (substr($value, 0, 1) === "'" && substr($value, -1) === "'") {
                $value = substr($value, 1, -1);
            }
            $env_array[$key] = $value;
        }
    }
    return $env_array;
}

function data_get_str($data, $key, $default = null): Stringable
{
    $str = data_get($data, $key, $default) ?? $default;
    return Str::of($str);
}

function generateFqdn(Server $server, string $random)
{
    $wildcard = data_get($server, 'settings.wildcard_domain');
    if (is_null($wildcard) || $wildcard === '') {
        $wildcard = sslip($server);
    }
    $url = Url::fromString($wildcard);
    $host = $url->getHost();
    $path = $url->getPath() === '/' ? '' : $url->getPath();
    $scheme = $url->getScheme();
    $finalFqdn = "$scheme://{$random}.$host$path";
    return $finalFqdn;
}
function sslip(Server $server)
{
    if (isDev() && $server->id === 0) {
        return "http://127.0.0.1.sslip.io";
    }
    if ($server->ip === 'host.docker.internal') {
        $baseIp = base_ip();
        return "http://$baseIp.sslip.io";
    }
    return "http://{$server->ip}.sslip.io";
}

function get_service_templates(bool $force = false): Collection
{
    if ($force) {
        try {
            $response = Http::retry(3, 50)->get(config('constants.services.official'));
            if ($response->failed()) {
                return collect([]);
            }
            $services = $response->json();
            return collect($services);
        } catch (\Throwable $e) {
            $services = File::get(base_path('templates/service-templates.json'));
            return collect(json_decode($services))->sortKeys();
        }
    } else {
        $services = File::get(base_path('templates/service-templates.json'));
        return collect(json_decode($services))->sortKeys();
    }
}

function getResourceByUuid(string $uuid, ?int $teamId = null)
{
    if (is_null($teamId)) {
        return null;
    }
    $resource = queryResourcesByUuid($uuid);
    if (!is_null($resource) && $resource->environment->project->team_id === $teamId) {
        return $resource;
    }
    return null;
}
function queryResourcesByUuid(string $uuid)
{
    $resource = null;
    $application = Application::whereUuid($uuid)->first();
    if ($application) return $application;
    $service = Service::whereUuid($uuid)->first();
    if ($service) return $service;
    $postgresql = StandalonePostgresql::whereUuid($uuid)->first();
    if ($postgresql) return $postgresql;
    $redis = StandaloneRedis::whereUuid($uuid)->first();
    if ($redis) return $redis;
    $mongodb = StandaloneMongodb::whereUuid($uuid)->first();
    if ($mongodb) return $mongodb;
    $mysql = StandaloneMysql::whereUuid($uuid)->first();
    if ($mysql) return $mysql;
    $mariadb = StandaloneMariadb::whereUuid($uuid)->first();
    if ($mariadb) return $mariadb;
    $keydb = StandaloneKeydb::whereUuid($uuid)->first();
    if ($keydb) return $keydb;
    $dragonfly = StandaloneDragonfly::whereUuid($uuid)->first();
    if ($dragonfly) return $dragonfly;
    $clickhouse = StandaloneClickhouse::whereUuid($uuid)->first();
    if ($clickhouse) return $clickhouse;
    return $resource;
}
function generatTagDeployWebhook($tag_name)
{
    $baseUrl = base_url();
    $api = Url::fromString($baseUrl) . '/api/v1';
    $endpoint = "/deploy?tag=$tag_name";
    $url = $api . $endpoint;
    return $url;
}
function generateDeployWebhook($resource)
{
    $baseUrl = base_url();
    $api = Url::fromString($baseUrl) . '/api/v1';
    $endpoint = '/deploy';
    $uuid = data_get($resource, 'uuid');
    $url = $api . $endpoint . "?uuid=$uuid&force=false";
    return $url;
}
function generateGitManualWebhook($resource, $type)
{
    if ($resource->source_id !== 0 && !is_null($resource->source_id)) {
        return null;
    }
    if ($resource->getMorphClass() === 'App\Models\Application') {
        $baseUrl = base_url();
        $api = Url::fromString($baseUrl) . "/webhooks/source/$type/events/manual";
        return $api;
    }
    return null;
}
function removeAnsiColors($text)
{
    return preg_replace('/\e[[][A-Za-z0-9];?[0-9]*m?/', '', $text);
}

function getTopLevelNetworks(Service|Application $resource)
{
    if ($resource->getMorphClass() === 'App\Models\Service') {
        if ($resource->docker_compose_raw) {
            try {
                $yaml = Yaml::parse($resource->docker_compose_raw);
            } catch (\Exception $e) {
                throw new \Exception($e->getMessage());
            }
            $services = data_get($yaml, 'services');
            $topLevelNetworks = collect(data_get($yaml, 'networks', []));
            $definedNetwork = collect([$resource->uuid]);
            $services = collect($services)->map(function ($service, $_) use ($topLevelNetworks, $definedNetwork) {
                $serviceNetworks = collect(data_get($service, 'networks', []));
                $hasHostNetworkMode = data_get($service, 'network_mode') === 'host' ? true : false;

                // Only add 'networks' key if 'network_mode' is not 'host'
                if (!$hasHostNetworkMode) {
                    // Collect/create/update networks
                    if ($serviceNetworks->count() > 0) {
                        foreach ($serviceNetworks as $networkName => $networkDetails) {
                            if ($networkName === 'default') {
                                continue;
                            }
                            // ignore alias
                            if ($networkDetails['aliases'] ?? false) {
                                continue;
                            }
                            $networkExists = $topLevelNetworks->contains(function ($value, $key) use ($networkName) {
                                return $value == $networkName || $key == $networkName;
                            });
                            if (!$networkExists) {
                                $topLevelNetworks->put($networkDetails, null);
                            }
                        }
                    }

                    $definedNetworkExists = $topLevelNetworks->contains(function ($value, $_) use ($definedNetwork) {
                        return $value == $definedNetwork;
                    });
                    if (!$definedNetworkExists) {
                        foreach ($definedNetwork as $network) {
                            $topLevelNetworks->put($network,  [
                                'name' => $network,
                                'external' => true
                            ]);
                        }
                    }
                }

                return $service;
            });
            return $topLevelNetworks->keys();
        }
    } else if ($resource->getMorphClass() === 'App\Models\Application') {
        try {
            $yaml = Yaml::parse($resource->docker_compose_raw);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        $server = $resource->destination->server;
        $topLevelNetworks = collect(data_get($yaml, 'networks', []));
        $services = data_get($yaml, 'services');
        $definedNetwork = collect([$resource->uuid]);
        $services = collect($services)->map(function ($service, $_) use ($topLevelNetworks, $definedNetwork) {
            $serviceNetworks = collect(data_get($service, 'networks', []));

            // Collect/create/update networks
            if ($serviceNetworks->count() > 0) {
                foreach ($serviceNetworks as $networkName => $networkDetails) {
                    if ($networkName === 'default') {
                        continue;
                    }
                    // ignore alias
                    if ($networkDetails['aliases'] ?? false) {
                        continue;
                    }
                    $networkExists = $topLevelNetworks->contains(function ($value, $key) use ($networkName) {
                        return $value == $networkName || $key == $networkName;
                    });
                    if (!$networkExists) {
                        $topLevelNetworks->put($networkDetails, null);
                    }
                }
            }
            $definedNetworkExists = $topLevelNetworks->contains(function ($value, $_) use ($definedNetwork) {
                return $value == $definedNetwork;
            });
            if (!$definedNetworkExists) {
                foreach ($definedNetwork as $network) {
                    $topLevelNetworks->put($network,  [
                        'name' => $network,
                        'external' => true
                    ]);
                }
            }
            return $service;
        });
        return $topLevelNetworks->keys();
    }
}

function parseDockerComposeFile(Service|Application $resource, bool $isNew = false, int $pull_request_id = 0, bool $is_pr = false)
{
    // ray()->clearAll();
    if ($resource->getMorphClass() === 'App\Models\Service') {
        if ($resource->docker_compose_raw) {
            try {
                $yaml = Yaml::parse($resource->docker_compose_raw);
            } catch (\Exception $e) {
                throw new \Exception($e->getMessage());
            }
            $allServices = get_service_templates();
            $topLevelVolumes = collect(data_get($yaml, 'volumes', []));
            $topLevelNetworks = collect(data_get($yaml, 'networks', []));
            $services = data_get($yaml, 'services');

            $generatedServiceFQDNS = collect([]);
            if (is_null($resource->destination)) {
                $destination = $resource->server->destinations()->first();
                if ($destination) {
                    $resource->destination()->associate($destination);
                    $resource->save();
                }
            }
            $definedNetwork = collect([$resource->uuid]);
            if ($topLevelVolumes->count() > 0) {
                $tempTopLevelVolumes = collect([]);
                foreach ($topLevelVolumes as $volumeName => $volume) {
                    if (is_null($volume)) {
                        continue;
                    }
                    $tempTopLevelVolumes->put($volumeName, $volume);
                }
                $topLevelVolumes = collect($tempTopLevelVolumes);
            }
            $services = collect($services)->map(function ($service, $serviceName) use ($topLevelVolumes, $topLevelNetworks, $definedNetwork, $isNew, $generatedServiceFQDNS, $resource, $allServices) {
                // Workarounds for beta users.
                if ($serviceName === 'registry') {
                    $tempServiceName = "docker-registry";
                } else {
                    $tempServiceName = $serviceName;
                }
                if (str(data_get($service, 'image'))->contains('glitchtip')) {
                    $tempServiceName = 'glitchtip';
                }
                if ($serviceName === 'supabase-kong') {
                    $tempServiceName = 'supabase';
                }
                $serviceDefinition = data_get($allServices, $tempServiceName);
                $predefinedPort = data_get($serviceDefinition, 'port');
                if ($serviceName === 'plausible') {
                    $predefinedPort = '8000';
                }
                // End of workarounds for beta users.
                $serviceVolumes = collect(data_get($service, 'volumes', []));
                $servicePorts = collect(data_get($service, 'ports', []));
                $serviceNetworks = collect(data_get($service, 'networks', []));
                $serviceVariables = collect(data_get($service, 'environment', []));
                $serviceLabels = collect(data_get($service, 'labels', []));
                $hasHostNetworkMode = data_get($service, 'network_mode') === 'host' ? true : false;
                if ($serviceLabels->count() > 0) {
                    $removedLabels = collect([]);
                    $serviceLabels = $serviceLabels->filter(function ($serviceLabel, $serviceLabelName) use ($removedLabels) {
                        if (!str($serviceLabel)->contains('=')) {
                            $removedLabels->put($serviceLabelName, $serviceLabel);
                            return false;
                        }
                        return $serviceLabel;
                    });
                    foreach ($removedLabels as $removedLabelName => $removedLabel) {
                        $serviceLabels->push("$removedLabelName=$removedLabel");
                    }
                }

                $containerName = "$serviceName-{$resource->uuid}";

                // Decide if the service is a database
                $isDatabase = isDatabaseImage(data_get_str($service, 'image'));
                $image = data_get_str($service, 'image');
                data_set($service, 'is_database', $isDatabase);

                // Create new serviceApplication or serviceDatabase
                if ($isDatabase) {
                    if ($isNew) {
                        $savedService = ServiceDatabase::create([
                            'name' => $serviceName,
                            'image' => $image,
                            'service_id' => $resource->id
                        ]);
                    } else {
                        $savedService = ServiceDatabase::where([
                            'name' => $serviceName,
                            'service_id' => $resource->id
                        ])->first();
                    }
                } else {
                    if ($isNew) {
                        $savedService = ServiceApplication::create([
                            'name' => $serviceName,
                            'image' => $image,
                            'service_id' => $resource->id
                        ]);
                    } else {
                        $savedService = ServiceApplication::where([
                            'name' => $serviceName,
                            'service_id' => $resource->id
                        ])->first();
                    }
                }
                if (is_null($savedService)) {
                    if ($isDatabase) {
                        $savedService = ServiceDatabase::create([
                            'name' => $serviceName,
                            'image' => $image,
                            'service_id' => $resource->id
                        ]);
                    } else {
                        $savedService = ServiceApplication::create([
                            'name' => $serviceName,
                            'image' => $image,
                            'service_id' => $resource->id
                        ]);
                    }
                }

                // Check if image changed
                if ($savedService->image !== $image) {
                    $savedService->image = $image;
                    $savedService->save();
                }
                // Collect/create/update networks
                if ($serviceNetworks->count() > 0) {
                    foreach ($serviceNetworks as $networkName => $networkDetails) {
                        if ($networkName === 'default') {
                            continue;
                        }
                        // ignore alias
                        if ($networkDetails['aliases'] ?? false) {
                            continue;
                        }
                        $networkExists = $topLevelNetworks->contains(function ($value, $key) use ($networkName) {
                            return $value == $networkName || $key == $networkName;
                        });
                        if (!$networkExists) {
                            $topLevelNetworks->put($networkDetails, null);
                        }
                    }
                }

                // Collect/create/update ports
                $collectedPorts = collect([]);
                if ($servicePorts->count() > 0) {
                    foreach ($servicePorts as $sport) {
                        if (is_string($sport) || is_numeric($sport)) {
                            $collectedPorts->push($sport);
                        }
                        if (is_array($sport)) {
                            $target = data_get($sport, 'target');
                            $published = data_get($sport, 'published');
                            $protocol = data_get($sport, 'protocol');
                            $collectedPorts->push("$target:$published/$protocol");
                        }
                    }
                }
                $savedService->ports = $collectedPorts->implode(',');
                $savedService->save();

                if (!$hasHostNetworkMode) {
                    // Add Coolify specific networks
                    $definedNetworkExists = $topLevelNetworks->contains(function ($value, $_) use ($definedNetwork) {
                        return $value == $definedNetwork;
                    });
                    if (!$definedNetworkExists) {
                        foreach ($definedNetwork as $network) {
                            $topLevelNetworks->put($network,  [
                                'name' => $network,
                                'external' => true
                            ]);
                        }
                    }
                    $networks = collect();
                    foreach ($serviceNetworks as $key => $serviceNetwork) {
                        if (gettype($serviceNetwork) === 'string') {
                            // networks:
                            //  - appwrite
                            $networks->put($serviceNetwork, null);
                        } else if (gettype($serviceNetwork) === 'array') {
                            // networks:
                            //   default:
                            //     ipv4_address: 192.168.203.254
                            // $networks->put($serviceNetwork, null);
                            $networks->put($key, $serviceNetwork);
                        }
                    }
                    foreach ($definedNetwork as $key => $network) {
                        $networks->put($network, null);
                    }
                    data_set($service, 'networks', $networks->toArray());
                }

                // Collect/create/update volumes
                if ($serviceVolumes->count() > 0) {
                    $serviceVolumes = $serviceVolumes->map(function ($volume) use ($savedService, $topLevelVolumes) {
                        $type = null;
                        $source = null;
                        $target = null;
                        $content = null;
                        $isDirectory = false;
                        if (is_string($volume)) {
                            $source = Str::of($volume)->before(':');
                            $target = Str::of($volume)->after(':')->beforeLast(':');
                            if ($source->startsWith('./') || $source->startsWith('/') || $source->startsWith('~')) {
                                $type = Str::of('bind');
                            } else {
                                $type = Str::of('volume');
                            }
                        } else if (is_array($volume)) {
                            $type = data_get_str($volume, 'type');
                            $source = data_get_str($volume, 'source');
                            $target = data_get_str($volume, 'target');
                            $content = data_get($volume, 'content');
                            $isDirectory = (bool) data_get($volume, 'isDirectory', false) || (bool) data_get($volume, 'is_directory', false);
                            $foundConfig = $savedService->fileStorages()->whereMountPath($target)->first();
                            if ($foundConfig) {
                                $contentNotNull = data_get($foundConfig, 'content');
                                if ($contentNotNull) {
                                    $content = $contentNotNull;
                                }
                                $isDirectory = (bool) data_get($volume, 'isDirectory', false) || (bool) data_get($volume, 'is_directory', false);
                            }
                        }
                        if ($type?->value() === 'bind') {
                            if ($source->value() === "/var/run/docker.sock") {
                                return $volume;
                            }
                            if ($source->value() === '/tmp' || $source->value() === '/tmp/') {
                                return $volume;
                            }
                            LocalFileVolume::updateOrCreate(
                                [
                                    'mount_path' => $target,
                                    'resource_id' => $savedService->id,
                                    'resource_type' => get_class($savedService)
                                ],
                                [
                                    'fs_path' => $source,
                                    'mount_path' => $target,
                                    'content' => $content,
                                    'is_directory' => $isDirectory,
                                    'resource_id' => $savedService->id,
                                    'resource_type' => get_class($savedService)
                                ]
                            );
                        } else if ($type->value() === 'volume') {
                            if ($topLevelVolumes->has($source->value())) {
                                $v = $topLevelVolumes->get($source->value());
                                if (data_get($v, 'driver_opts')) {
                                    return $volume;
                                }
                            }
                            $slugWithoutUuid = Str::slug($source, '-');
                            $name = "{$savedService->service->uuid}_{$slugWithoutUuid}";
                            if (is_string($volume)) {
                                $source = Str::of($volume)->before(':');
                                $target = Str::of($volume)->after(':')->beforeLast(':');
                                $source = $name;
                                $volume = "$source:$target";
                            } else if (is_array($volume)) {
                                data_set($volume, 'source', $name);
                            }
                            $topLevelVolumes->put($name, [
                                'name' => $name,
                            ]);
                            LocalPersistentVolume::updateOrCreate(
                                [
                                    'mount_path' => $target,
                                    'resource_id' => $savedService->id,
                                    'resource_type' => get_class($savedService)
                                ],
                                [
                                    'name' => $name,
                                    'mount_path' => $target,
                                    'resource_id' => $savedService->id,
                                    'resource_type' => get_class($savedService)
                                ]
                            );
                        }
                        dispatch(new ServerFilesFromServerJob($savedService));
                        return $volume;
                    });
                    data_set($service, 'volumes', $serviceVolumes->toArray());
                }

                // Add env_file with at least .env to the service
                // $envFile = collect(data_get($service, 'env_file', []));
                // if ($envFile->count() > 0) {
                //     if (!$envFile->contains('.env')) {
                //         $envFile->push('.env');
                //     }
                // } else {
                //     $envFile = collect(['.env']);
                // }
                // data_set($service, 'env_file', $envFile->toArray());


                // Get variables from the service
                foreach ($serviceVariables as $variableName => $variable) {
                    if (is_numeric($variableName)) {
                        $variable = Str::of($variable);
                        if ($variable->contains('=')) {
                            // - SESSION_SECRET=123
                            // - SESSION_SECRET=
                            $key = $variable->before('=');
                            $value = $variable->after('=');
                        } else {
                            // - SESSION_SECRET
                            $key = $variable;
                            $value = null;
                        }
                    } else {
                        // SESSION_SECRET: 123
                        // SESSION_SECRET:
                        $key = Str::of($variableName);
                        $value = Str::of($variable);
                    }
                    if ($key->startsWith('SERVICE_FQDN')) {
                        if ($isNew || $savedService->fqdn === null) {
                            $name = $key->after('SERVICE_FQDN_')->beforeLast('_')->lower();
                            $fqdn = generateFqdn($resource->server, "{$name->value()}-{$resource->uuid}");
                            if (substr_count($key->value(), '_') === 3) {
                                // SERVICE_FQDN_UMAMI_1000
                                $port = $key->afterLast('_');
                            } else {
                                $last = $key->afterLast('_');
                                if (is_numeric($last->value())) {
                                    // SERVICE_FQDN_3001
                                    $port = $last;
                                } else {
                                    // SERVICE_FQDN_UMAMI
                                    $port = null;
                                }
                            }
                            if ($port) {
                                $fqdn = "$fqdn:$port";
                            }
                            if (substr_count($key->value(), '_') >= 2) {
                                if ($value) {
                                    $path = $value->value();
                                } else {
                                    $path = null;
                                }
                                if ($generatedServiceFQDNS->count() > 0) {
                                    $alreadyGenerated = $generatedServiceFQDNS->has($key->value());
                                    if ($alreadyGenerated) {
                                        $fqdn = $generatedServiceFQDNS->get($key->value());
                                    } else {
                                        $generatedServiceFQDNS->put($key->value(), $fqdn);
                                    }
                                } else {
                                    $generatedServiceFQDNS->put($key->value(), $fqdn);
                                }
                                $fqdn = "$fqdn$path";
                            }

                            if (!$isDatabase) {
                                if ($savedService->fqdn) {
                                    data_set($savedService, 'fqdn', $savedService->fqdn . ',' . $fqdn);
                                } else {
                                    data_set($savedService, 'fqdn', $fqdn);
                                }
                                $savedService->save();
                            }
                            EnvironmentVariable::create([
                                'key' => $key,
                                'value' => $fqdn,
                                'is_build_time' => false,
                                'service_id' => $resource->id,
                                'is_preview' => false,
                            ]);
                        }
                        // Caddy needs exact port in some cases.
                        if ($predefinedPort && !$key->endsWith("_{$predefinedPort}")) {
                            $fqdns_exploded = str($savedService->fqdn)->explode(',');
                            if ($fqdns_exploded->count() > 1) {
                                continue;
                            }
                            $env = EnvironmentVariable::where([
                                'key' => $key,
                                'service_id' => $resource->id,
                            ])->first();
                            if ($env) {
                                $env_url = Url::fromString($savedService->fqdn);
                                $env_port = $env_url->getPort();
                                if ($env_port !== $predefinedPort) {
                                    $env_url = $env_url->withPort($predefinedPort);
                                    $savedService->fqdn = $env_url->__toString();
                                    $savedService->save();
                                }
                            }
                        }
                        // data_forget($service, "environment.$variableName");
                        // $yaml = data_forget($yaml, "services.$serviceName.environment.$variableName");
                        // if (count(data_get($yaml, 'services.' . $serviceName . '.environment')) === 0) {
                        //     $yaml = data_forget($yaml, "services.$serviceName.environment");
                        // }
                        continue;
                    }
                    if ($value?->startsWith('$')) {
                        $foundEnv = EnvironmentVariable::where([
                            'key' => $key,
                            'service_id' => $resource->id,
                        ])->first();
                        $value = Str::of(replaceVariables($value));
                        $key = $value;
                        if ($value->startsWith('SERVICE_')) {
                            $foundEnv = EnvironmentVariable::where([
                                'key' => $key,
                                'service_id' => $resource->id,
                            ])->first();
                            ['command' => $command, 'forService' => $forService, 'generatedValue' => $generatedValue, 'port' => $port] = parseEnvVariable($value);
                            if (!is_null($command)) {
                                if ($command?->value() === 'FQDN' || $command?->value() === 'URL') {
                                    if (Str::lower($forService) === $serviceName) {
                                        $fqdn = generateFqdn($resource->server, $containerName);
                                    } else {
                                        $fqdn = generateFqdn($resource->server, Str::lower($forService) . '-' . $resource->uuid);
                                    }
                                    if ($port) {
                                        $fqdn = "$fqdn:$port";
                                    }
                                    if ($foundEnv) {
                                        $fqdn = data_get($foundEnv, 'value');
                                        // if ($savedService->fqdn) {
                                        //     $savedServiceFqdn = Url::fromString($savedService->fqdn);
                                        //     $parsedFqdn = Url::fromString($fqdn);
                                        //     $savedServicePath = $savedServiceFqdn->getPath();
                                        //     $parsedFqdnPath = $parsedFqdn->getPath();
                                        //     if ($savedServicePath != $parsedFqdnPath) {
                                        //         $fqdn = $parsedFqdn->withPath($savedServicePath)->__toString();
                                        //         $foundEnv->value = $fqdn;
                                        //         $foundEnv->save();
                                        //     }
                                        // }
                                    } else {
                                        if ($command->value() === 'URL') {
                                            $fqdn = Str::of($fqdn)->after('://')->value();
                                        }
                                        EnvironmentVariable::create([
                                            'key' => $key,
                                            'value' => $fqdn,
                                            'is_build_time' => false,
                                            'service_id' => $resource->id,
                                            'is_preview' => false,
                                        ]);
                                    }
                                    if (!$isDatabase) {
                                        if ($command->value() === 'FQDN' && is_null($savedService->fqdn) && !$foundEnv) {
                                            $savedService->fqdn = $fqdn;
                                            $savedService->save();
                                        }
                                        // Caddy needs exact port in some cases.
                                        if ($predefinedPort && !$key->endsWith("_{$predefinedPort}") && $command?->value() === 'FQDN' && $resource->server->proxyType() === 'CADDY') {
                                            $fqdns_exploded = str($savedService->fqdn)->explode(',');
                                            if ($fqdns_exploded->count() > 1) {
                                                continue;
                                            }
                                            $env = EnvironmentVariable::where([
                                                'key' => $key,
                                                'service_id' => $resource->id,
                                            ])->first();
                                            if ($env) {
                                                $env_url = Url::fromString($env->value);
                                                $env_port = $env_url->getPort();
                                                if ($env_port !== $predefinedPort) {
                                                    $env_url = $env_url->withPort($predefinedPort);
                                                    $savedService->fqdn = $env_url->__toString();
                                                    $savedService->save();
                                                }
                                            }
                                        }
                                    }
                                } else {
                                    $generatedValue = generateEnvValue($command, $resource);
                                    if (!$foundEnv) {
                                        EnvironmentVariable::create([
                                            'key' => $key,
                                            'value' => $generatedValue,
                                            'is_build_time' => false,
                                            'service_id' => $resource->id,
                                            'is_preview' => false,
                                        ]);
                                    }
                                }
                            }
                        } else {
                            if ($value->contains(':-')) {
                                $key = $value->before(':');
                                $defaultValue = $value->after(':-');
                            } else if ($value->contains('-')) {
                                $key = $value->before('-');
                                $defaultValue = $value->after('-');
                            } else if ($value->contains(':?')) {
                                $key = $value->before(':');
                                $defaultValue = $value->after(':?');
                            } else if ($value->contains('?')) {
                                $key = $value->before('?');
                                $defaultValue = $value->after('?');
                            } else {
                                $key = $value;
                                $defaultValue = null;
                            }
                            $foundEnv = EnvironmentVariable::where([
                                'key' => $key,
                                'service_id' => $resource->id,
                            ])->first();
                            if ($foundEnv) {
                                $defaultValue = data_get($foundEnv, 'value');
                            }
                            EnvironmentVariable::updateOrCreate([
                                'key' => $key,
                                'service_id' => $resource->id,
                            ], [
                                'value' => $defaultValue,
                                'is_build_time' => false,
                                'service_id' => $resource->id,
                                'is_preview' => false,
                            ]);
                        }
                    }
                }
                // Add labels to the service
                if ($savedService->serviceType()) {
                    $fqdns = generateServiceSpecificFqdns($savedService);
                } else {
                    $fqdns = collect(data_get($savedService, 'fqdns'))->filter();
                }
                $defaultLabels = defaultLabels($resource->id, $containerName, type: 'service', subType: $isDatabase ? 'database' : 'application', subId: $savedService->id);
                $serviceLabels = $serviceLabels->merge($defaultLabels);
                if (!$isDatabase && $fqdns->count() > 0) {
                    if ($fqdns) {
                        $serviceLabels = $serviceLabels->merge(fqdnLabelsForTraefik(
                            uuid: $resource->uuid,
                            domains: $fqdns,
                            is_force_https_enabled: true,
                            serviceLabels: $serviceLabels,
                            is_gzip_enabled: $savedService->isGzipEnabled(),
                            is_stripprefix_enabled: $savedService->isStripprefixEnabled(),
                            service_name: $serviceName,
                            image: data_get($service, 'image')
                        ));
                        $serviceLabels = $serviceLabels->merge(fqdnLabelsForCaddy(
                            network: $resource->destination->network,
                            uuid: $resource->uuid,
                            domains: $fqdns,
                            is_force_https_enabled: true,
                            serviceLabels: $serviceLabels,
                            is_gzip_enabled: $savedService->isGzipEnabled(),
                            is_stripprefix_enabled: $savedService->isStripprefixEnabled(),
                            service_name: $serviceName,
                            image: data_get($service, 'image')
                        ));
                    }
                }
                if ($resource->server->isLogDrainEnabled() && $savedService->isLogDrainEnabled()) {
                    data_set($service, 'logging', [
                        'driver' => 'fluentd',
                        'options' => [
                            'fluentd-address' => "tcp://127.0.0.1:24224",
                            'fluentd-async' => "true",
                            'fluentd-sub-second-precision' => "true",
                        ]
                    ]);
                }
                if ($serviceLabels->count() > 0) {
                    if ($resource->is_container_label_escape_enabled) {
                        $serviceLabels = $serviceLabels->map(function ($value, $key) {
                            return escapeDollarSign($value);
                        });
                    }
                }
                data_set($service, 'labels', $serviceLabels->toArray());
                data_forget($service, 'is_database');
                if (!data_get($service, 'restart')) {
                    data_set($service, 'restart', RESTART_MODE);
                }
                if (data_get($service, 'restart') === 'no' || data_get($service, 'exclude_from_hc')) {
                    $savedService->update(['exclude_from_status' => true]);
                }
                data_set($service, 'container_name', $containerName);
                data_forget($service, 'volumes.*.content');
                data_forget($service, 'volumes.*.isDirectory');
                data_forget($service, 'volumes.*.is_directory');
                data_forget($service, 'exclude_from_hc');

                // Remove unnecessary variables from service.environment
                // $withoutServiceEnvs = collect([]);
                // collect(data_get($service, 'environment'))->each(function ($value, $key) use ($withoutServiceEnvs) {
                //     ray($key, $value);
                //     if (!Str::of($key)->startsWith('$SERVICE_') && !Str::of($value)->startsWith('SERVICE_')) {
                //         $k = Str::of($value)->before("=");
                //         $v = Str::of($value)->after("=");
                //         $withoutServiceEnvs->put($k->value(), $v->value());
                //     }
                // });
                // ray($withoutServiceEnvs);
                // data_set($service, 'environment', $withoutServiceEnvs->toArray());
                updateCompose($savedService);
                return $service;
            });
            $finalServices = [
                'services' => $services->toArray(),
                'volumes' => $topLevelVolumes->toArray(),
                'networks' => $topLevelNetworks->toArray(),
            ];
            $yaml = data_forget($yaml, 'services.*.volumes.*.content');
            $resource->docker_compose_raw = Yaml::dump($yaml, 10, 2);
            $resource->docker_compose = Yaml::dump($finalServices, 10, 2);
            $resource->save();
            $resource->saveComposeConfigs();
            return collect($finalServices);
        } else {
            return collect([]);
        }
    } else if ($resource->getMorphClass() === 'App\Models\Application') {
        $isSameDockerComposeFile = false;
        if ($resource->dockerComposePrLocation() === $resource->dockerComposeLocation()) {
            $isSameDockerComposeFile = true;
            $is_pr = false;
        }
        if ($is_pr) {
            try {
                $yaml = Yaml::parse($resource->docker_compose_pr_raw);
            } catch (\Exception $e) {
                return;
            }
        } else {
            try {
                $yaml = Yaml::parse($resource->docker_compose_raw);
            } catch (\Exception $e) {
                return;
            }
        }
        $server = $resource->destination->server;
        $topLevelVolumes = collect(data_get($yaml, 'volumes', []));
        if ($pull_request_id !== 0) {
            $topLevelVolumes = collect([]);
        }

        if ($topLevelVolumes->count() > 0) {
            $tempTopLevelVolumes = collect([]);
            foreach ($topLevelVolumes as $volumeName => $volume) {
                if (is_null($volume)) {
                    continue;
                }
                $tempTopLevelVolumes->put($volumeName, $volume);
            }
            $topLevelVolumes = collect($tempTopLevelVolumes);
        }

        $topLevelNetworks = collect(data_get($yaml, 'networks', []));
        $services = data_get($yaml, 'services');

        $generatedServiceFQDNS = collect([]);
        if (is_null($resource->destination)) {
            $destination = $server->destinations()->first();
            if ($destination) {
                $resource->destination()->associate($destination);
                $resource->save();
            }
        }
        $definedNetwork = collect([$resource->uuid]);
        if ($pull_request_id !== 0) {
            $definedNetwork = collect(["{$resource->uuid}-$pull_request_id"]);
        }
        $services = collect($services)->map(function ($service, $serviceName) use ($topLevelVolumes, $topLevelNetworks, $definedNetwork, $isNew, $generatedServiceFQDNS, $resource, $server, $pull_request_id) {
            $serviceVolumes = collect(data_get($service, 'volumes', []));
            $servicePorts = collect(data_get($service, 'ports', []));
            $serviceNetworks = collect(data_get($service, 'networks', []));
            $serviceVariables = collect(data_get($service, 'environment', []));
            $serviceDependencies = collect(data_get($service, 'depends_on', []));
            $serviceLabels = collect(data_get($service, 'labels', []));
            $serviceBuildVariables = collect(data_get($service, 'build.args', []));
            $serviceVariables = $serviceVariables->merge($serviceBuildVariables);
            if ($serviceLabels->count() > 0) {
                $removedLabels = collect([]);
                $serviceLabels = $serviceLabels->filter(function ($serviceLabel, $serviceLabelName) use ($removedLabels) {
                    if (!str($serviceLabel)->contains('=')) {
                        $removedLabels->put($serviceLabelName, $serviceLabel);
                        return false;
                    }
                    return $serviceLabel;
                });
                foreach ($removedLabels as $removedLabelName => $removedLabel) {
                    $serviceLabels->push("$removedLabelName=$removedLabel");
                }
            }

            $baseName = generateApplicationContainerName($resource, $pull_request_id);
            $containerName = "$serviceName-$baseName";
            if (count($serviceVolumes) > 0) {
                $serviceVolumes = $serviceVolumes->map(function ($volume) use ($resource, $topLevelVolumes, $pull_request_id) {
                    if (is_string($volume)) {
                        $volume = str($volume);
                        if ($volume->contains(':') && !$volume->startsWith('/')) {
                            $name = $volume->before(':');
                            $mount = $volume->after(':');
                            if ($name->startsWith('.') || $name->startsWith('~')) {
                                $dir = base_configuration_dir() . '/applications/' . $resource->uuid;
                                if ($name->startsWith('.')) {
                                    $name = $name->replaceFirst('.', $dir);
                                }
                                if ($name->startsWith('~')) {
                                    $name = $name->replaceFirst('~', $dir);
                                }
                                if ($pull_request_id !== 0) {
                                    $name = $name . "-pr-$pull_request_id";
                                }
                                $volume = str("$name:$mount");
                            } else {
                                if ($pull_request_id !== 0) {
                                    $name = $name . "-pr-$pull_request_id";
                                    $volume = str("$name:$mount");
                                    if ($topLevelVolumes->has($name)) {
                                        $v = $topLevelVolumes->get($name);
                                        if (data_get($v, 'driver_opts')) {
                                            // Do nothing
                                        }
                                    } else {
                                        $topLevelVolumes->put($name, [
                                            'name' => $name,
                                        ]);
                                    }
                                } else {
                                    if ($topLevelVolumes->has($name->value())) {
                                        $v = $topLevelVolumes->get($name->value());
                                        if (data_get($v, 'driver_opts')) {
                                            // Do nothing
                                        }
                                    } else {
                                        $topLevelVolumes->put($name->value(), [
                                            'name' => $name->value(),
                                        ]);
                                    }
                                }
                            }
                        } else {
                            if ($volume->startsWith('/')) {
                                $name = $volume->before(':');
                                $mount = $volume->after(':');
                                if ($pull_request_id !== 0) {
                                    $name = $name . "-pr-$pull_request_id";
                                }
                                $volume = str("$name:$mount");
                            }
                        }
                    } else if (is_array($volume)) {
                        $source = data_get($volume, 'source');
                        $target = data_get($volume, 'target');
                        $read_only = data_get($volume, 'read_only');
                        if ($source && $target) {
                            if ((str($source)->startsWith('.') || str($source)->startsWith('~'))) {
                                $dir = base_configuration_dir() . '/applications/' . $resource->uuid;
                                if (str($source, '.')) {
                                    $source = str($source)->replaceFirst('.', $dir);
                                }
                                if (str($source, '~')) {
                                    $source = str($source)->replaceFirst('~', $dir);
                                }
                                if ($pull_request_id !== 0) {
                                    $source = $source . "-pr-$pull_request_id";
                                }
                                if ($read_only) {
                                    data_set($volume, 'source', $source . ':' . $target . ':ro');
                                } else {
                                    data_set($volume, 'source', $source . ':' . $target);
                                }
                            } else {
                                if ($pull_request_id !== 0) {
                                    $source = $source . "-pr-$pull_request_id";
                                }
                                if ($read_only) {
                                    data_set($volume, 'source', $source . ':' . $target . ':ro');
                                } else {
                                    data_set($volume, 'source', $source . ':' . $target);
                                }
                                if (!str($source)->startsWith('/')) {
                                    if ($topLevelVolumes->has($source)) {
                                        $v = $topLevelVolumes->get($source);
                                        if (data_get($v, 'driver_opts')) {
                                            // Do nothing
                                        }
                                    } else {
                                        $topLevelVolumes->put($source, [
                                            'name' => $source,
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                    if (is_array($volume)) {
                        return data_get($volume, 'source');
                    }
                    return $volume->value();
                });
                data_set($service, 'volumes', $serviceVolumes->toArray());
            }

            if ($pull_request_id !== 0 && count($serviceDependencies) > 0) {
                $serviceDependencies = $serviceDependencies->map(function ($dependency) use ($pull_request_id) {
                    return $dependency . "-pr-$pull_request_id";
                });
                data_set($service, 'depends_on', $serviceDependencies->toArray());
            }

            // Decide if the service is a database
            $isDatabase = isDatabaseImage(data_get_str($service, 'image'));
            data_set($service, 'is_database', $isDatabase);

            // Collect/create/update networks
            if ($serviceNetworks->count() > 0) {
                foreach ($serviceNetworks as $networkName => $networkDetails) {
                    if ($networkName === 'default') {
                        continue;
                    }
                    // ignore alias
                    if ($networkDetails['aliases'] ?? false) {
                        continue;
                    }
                    $networkExists = $topLevelNetworks->contains(function ($value, $key) use ($networkName) {
                        return $value == $networkName || $key == $networkName;
                    });
                    if (!$networkExists) {
                        $topLevelNetworks->put($networkDetails, null);
                    }
                }
            }
            // Collect/create/update ports
            $collectedPorts = collect([]);
            if ($servicePorts->count() > 0) {
                foreach ($servicePorts as $sport) {
                    if (is_string($sport) || is_numeric($sport)) {
                        $collectedPorts->push($sport);
                    }
                    if (is_array($sport)) {
                        $target = data_get($sport, 'target');
                        $published = data_get($sport, 'published');
                        $protocol = data_get($sport, 'protocol');
                        $collectedPorts->push("$target:$published/$protocol");
                    }
                }
            }
            if ($collectedPorts->count() > 0) {
                // ray($collectedPorts->implode(','));
            }
            $definedNetworkExists = $topLevelNetworks->contains(function ($value, $_) use ($definedNetwork) {
                return $value == $definedNetwork;
            });
            if (!$definedNetworkExists) {
                foreach ($definedNetwork as $network) {
                    if ($pull_request_id !== 0) {
                        $topLevelNetworks->put($network,  [
                            'name' => $network,
                            'external' => true
                        ]);
                    } else {
                        $topLevelNetworks->put($network,  [
                            'name' => $network,
                            'external' => true
                        ]);
                    }
                }
            }
            $networks = collect();
            foreach ($serviceNetworks as $key => $serviceNetwork) {
                if (gettype($serviceNetwork) === 'string') {
                    // networks:
                    //  - appwrite
                    $networks->put($serviceNetwork, null);
                } else if (gettype($serviceNetwork) === 'array') {
                    // networks:
                    //   default:
                    //     ipv4_address: 192.168.203.254
                    // $networks->put($serviceNetwork, null);
                    $networks->put($key, $serviceNetwork);
                }
            }
            foreach ($definedNetwork as $key => $network) {
                $networks->put($network, null);
            }
            if (data_get($resource, 'settings.connect_to_docker_network')) {
                $network = $resource->destination->network;
                $networks->put($network, null);
                $topLevelNetworks->put($network,  [
                    'name' => $network,
                    'external' => true
                ]);
            }
            data_set($service, 'networks', $networks->toArray());
            // Get variables from the service
            foreach ($serviceVariables as $variableName => $variable) {
                if (is_numeric($variableName)) {
                    $variable = Str::of($variable);
                    if ($variable->contains('=')) {
                        // - SESSION_SECRET=123
                        // - SESSION_SECRET=
                        $key = $variable->before('=');
                        $value = $variable->after('=');
                    } else {
                        // - SESSION_SECRET
                        $key = $variable;
                        $value = null;
                    }
                } else {
                    // SESSION_SECRET: 123
                    // SESSION_SECRET:
                    $key = Str::of($variableName);
                    $value = Str::of($variable);
                }
                if ($key->startsWith('SERVICE_FQDN')) {
                    if ($isNew) {
                        $name = $key->after('SERVICE_FQDN_')->beforeLast('_')->lower();
                        $fqdn = generateFqdn($server, "{$name->value()}-{$resource->uuid}");
                        if (substr_count($key->value(), '_') === 3) {
                            // SERVICE_FQDN_UMAMI_1000
                            $port = $key->afterLast('_');
                        } else {
                            // SERVICE_FQDN_UMAMI
                            $port = null;
                        }
                        if ($port) {
                            $fqdn = "$fqdn:$port";
                        }
                        if (substr_count($key->value(), '_') >= 2) {
                            if ($value) {
                                $path = $value->value();
                            } else {
                                $path = null;
                            }
                            if ($generatedServiceFQDNS->count() > 0) {
                                $alreadyGenerated = $generatedServiceFQDNS->has($key->value());
                                if ($alreadyGenerated) {
                                    $fqdn = $generatedServiceFQDNS->get($key->value());
                                } else {
                                    $generatedServiceFQDNS->put($key->value(), $fqdn);
                                }
                            } else {
                                $generatedServiceFQDNS->put($key->value(), $fqdn);
                            }
                            $fqdn = "$fqdn$path";
                        }
                    }
                    continue;
                }
                if ($value?->startsWith('$')) {
                    $foundEnv = EnvironmentVariable::where([
                        'key' => $key,
                        'application_id' => $resource->id,
                        'is_preview' => false,
                    ])->first();
                    $value = Str::of(replaceVariables($value));
                    $key = $value;
                    if ($value->startsWith('SERVICE_')) {
                        $foundEnv = EnvironmentVariable::where([
                            'key' => $key,
                            'application_id' => $resource->id,
                        ])->first();
                        ['command' => $command, 'forService' => $forService, 'generatedValue' => $generatedValue, 'port' => $port] = parseEnvVariable($value);
                        if (!is_null($command)) {
                            if ($command?->value() === 'FQDN' || $command?->value() === 'URL') {
                                if (Str::lower($forService) === $serviceName) {
                                    $fqdn = generateFqdn($server, $containerName);
                                } else {
                                    $fqdn = generateFqdn($server, Str::lower($forService) . '-' . $resource->uuid);
                                }
                                if ($port) {
                                    $fqdn = "$fqdn:$port";
                                }
                                if ($foundEnv) {
                                    $fqdn = data_get($foundEnv, 'value');
                                } else {
                                    if ($command?->value() === 'URL') {
                                        $fqdn = Str::of($fqdn)->after('://')->value();
                                    }
                                    EnvironmentVariable::create([
                                        'key' => $key,
                                        'value' => $fqdn,
                                        'is_build_time' => false,
                                        'application_id' => $resource->id,
                                        'is_preview' => false,
                                    ]);
                                }
                            } else {
                                $generatedValue = generateEnvValue($command);
                                if (!$foundEnv) {
                                    EnvironmentVariable::create([
                                        'key' => $key,
                                        'value' => $generatedValue,
                                        'is_build_time' => false,
                                        'application_id' => $resource->id,
                                        'is_preview' => false,
                                    ]);
                                }
                            }
                        }
                    } else {
                        if ($value->contains(':-')) {
                            $key = $value->before(':');
                            $defaultValue = $value->after(':-');
                        } else if ($value->contains('-')) {
                            $key = $value->before('-');
                            $defaultValue = $value->after('-');
                        } else if ($value->contains(':?')) {
                            $key = $value->before(':');
                            $defaultValue = $value->after(':?');
                        } else if ($value->contains('?')) {
                            $key = $value->before('?');
                            $defaultValue = $value->after('?');
                        } else {
                            $key = $value;
                            $defaultValue = null;
                        }
                        $foundEnv = EnvironmentVariable::where([
                            'key' => $key,
                            'application_id' => $resource->id,
                            'is_preview' => false,
                        ])->first();
                        if ($foundEnv) {
                            $defaultValue = data_get($foundEnv, 'value');
                        }
                        $isBuildTime = data_get($foundEnv, 'is_build_time', false);
                        if ($foundEnv) {
                            $foundEnv->update([
                                'key' => $key,
                                'application_id' => $resource->id,
                                'is_build_time' => $isBuildTime,
                                'value' => $defaultValue,
                            ]);
                        } else {
                            EnvironmentVariable::create([
                                'key' => $key,
                                'value' => $defaultValue,
                                'is_build_time' => $isBuildTime,
                                'application_id' => $resource->id,
                                'is_preview' => false,
                            ]);
                        }
                    }
                }
            }
            // Add labels to the service
            if ($resource->serviceType()) {
                $fqdns = generateServiceSpecificFqdns($resource);
            } else {
                $domains = collect(json_decode($resource->docker_compose_domains)) ?? [];
                if ($domains) {
                    $fqdns = data_get($domains, "$serviceName.domain");
                    if ($fqdns) {
                        $fqdns = str($fqdns)->explode(',');
                        if ($pull_request_id !== 0) {
                            $fqdns = $fqdns->map(function ($fqdn) use ($pull_request_id, $resource) {
                                $preview = ApplicationPreview::findPreviewByApplicationAndPullId($resource->id, $pull_request_id);
                                $url = Url::fromString($fqdn);
                                $template = $resource->preview_url_template;
                                $host = $url->getHost();
                                $schema = $url->getScheme();
                                $random = new Cuid2(7);
                                $preview_fqdn = str_replace('{{random}}', $random, $template);
                                $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
                                $preview_fqdn = str_replace('{{pr_id}}', $pull_request_id, $preview_fqdn);
                                $preview_fqdn = "$schema://$preview_fqdn";
                                $preview->fqdn = $preview_fqdn;
                                $preview->save();
                                return $preview_fqdn;
                            });
                        }
                        $serviceLabels = $serviceLabels->merge(fqdnLabelsForTraefik(
                            uuid: $resource->uuid,
                            domains: $fqdns,
                            serviceLabels: $serviceLabels,
                            generate_unique_uuid: $resource->build_pack === 'dockercompose',
                            image: data_get($service, 'image')
                        ));
                        $serviceLabels = $serviceLabels->merge(fqdnLabelsForCaddy(
                            network: $resource->destination->network,
                            uuid: $resource->uuid,
                            domains: $fqdns,
                            serviceLabels: $serviceLabels,
                            image: data_get($service, 'image')
                        ));
                    }
                }
            }
            $defaultLabels = defaultLabels($resource->id, $containerName, $pull_request_id, type: 'application');
            $serviceLabels = $serviceLabels->merge($defaultLabels);

            if ($server->isLogDrainEnabled() && $resource->isLogDrainEnabled()) {
                data_set($service, 'logging', [
                    'driver' => 'fluentd',
                    'options' => [
                        'fluentd-address' => "tcp://127.0.0.1:24224",
                        'fluentd-async' => "true",
                        'fluentd-sub-second-precision' => "true",
                    ]
                ]);
            }
            if ($serviceLabels->count() > 0) {
                if ($resource->settings->is_container_label_escape_enabled) {
                    $serviceLabels = $serviceLabels->map(function ($value, $key) {
                        return escapeDollarSign($value);
                    });
                }
            }
            data_set($service, 'labels', $serviceLabels->toArray());
            data_forget($service, 'is_database');
            if (!data_get($service, 'restart')) {
                data_set($service, 'restart', RESTART_MODE);
            }
            data_set($service, 'container_name', $containerName);
            data_forget($service, 'volumes.*.content');
            data_forget($service, 'volumes.*.isDirectory');

            return $service;
        });
        if ($pull_request_id !== 0) {
            $services->each(function ($service, $serviceName) use ($pull_request_id, $services) {
                $services[$serviceName . "-pr-$pull_request_id"] = $service;
                data_forget($services, $serviceName);
            });
        }
        $finalServices = [
            'services' => $services->toArray(),
            'volumes' => $topLevelVolumes->toArray(),
            'networks' => $topLevelNetworks->toArray(),
        ];
        if ($isSameDockerComposeFile) {
            $resource->docker_compose_pr_raw = Yaml::dump($yaml, 10, 2);
            $resource->docker_compose_pr = Yaml::dump($finalServices, 10, 2);
            $resource->docker_compose_raw = Yaml::dump($yaml, 10, 2);
            $resource->docker_compose = Yaml::dump($finalServices, 10, 2);
        } else {
            if ($is_pr) {
                $resource->docker_compose_pr_raw = Yaml::dump($yaml, 10, 2);
                $resource->docker_compose_pr = Yaml::dump($finalServices, 10, 2);
            } else {
                $resource->docker_compose_raw = Yaml::dump($yaml, 10, 2);
                $resource->docker_compose = Yaml::dump($finalServices, 10, 2);
            }
        }
        $resource->save();
        return collect($finalServices);
    }
}

function parseEnvVariable(Str|string $value)
{
    $value = str($value);
    $count = substr_count($value->value(), '_');
    $command = null;
    $forService = null;
    $generatedValue = null;
    $port = null;
    if ($value->startsWith('SERVICE')) {
        if ($count === 2) {
            if ($value->startsWith('SERVICE_FQDN') || $value->startsWith('SERVICE_URL')) {
                // SERVICE_FQDN_UMAMI
                $command = $value->after('SERVICE_')->beforeLast('_');
                $forService = $value->afterLast('_');
            } else {
                // SERVICE_BASE64_UMAMI
                $command = $value->after('SERVICE_')->beforeLast('_');
            }
        }
        if ($count === 3) {
            if ($value->startsWith('SERVICE_FQDN') || $value->startsWith('SERVICE_URL')) {
                // SERVICE_FQDN_UMAMI_1000
                $command = $value->after('SERVICE_')->before('_');
                $forService = $value->after('SERVICE_')->after('_')->before('_');
                $port = $value->afterLast('_');
                if (filter_var($port, FILTER_VALIDATE_INT) === false) {
                    $port = null;
                }
            } else {
                // SERVICE_BASE64_64_UMAMI
                $command = $value->after('SERVICE_')->beforeLast('_');
            }
        }
    }
    return [
        'command' => $command,
        'forService' => $forService,
        'generatedValue' => $generatedValue,
        'port' => $port,
    ];
}
function generateEnvValue(string $command, ?Service $service = null)
{
    switch ($command) {
        case 'PASSWORD':
            $generatedValue = Str::password(symbols: false);
            break;
        case 'PASSWORD_64':
            $generatedValue = Str::password(length: 64, symbols: false);
            break;
            // This is not base64, it's just a random string
        case 'BASE64_64':
            $generatedValue = Str::random(64);
            break;
        case 'BASE64_128':
            $generatedValue = Str::random(128);
            break;
        case 'BASE64':
        case 'BASE64_32':
            $generatedValue = Str::random(32);
            break;
            // This is base64,
        case 'REALBASE64_64':
            $generatedValue = base64_encode(Str::random(64));
            break;
        case 'REALBASE64_128':
            $generatedValue = base64_encode(Str::random(128));
            break;
        case 'REALBASE64':
        case 'REALBASE64_32':
            $generatedValue = base64_encode(Str::random(32));
            break;
        case 'USER':
            $generatedValue = Str::random(16);
            break;
        case 'SUPABASEANON':
            $signingKey = $service->environment_variables()->where('key', 'SERVICE_PASSWORD_JWT')->first();
            if (is_null($signingKey)) {
                return;
            } else {
                $signingKey = $signingKey->value;
            }
            $key = InMemory::plainText($signingKey);
            $algorithm = new Sha256();
            $tokenBuilder = (new Builder(new JoseEncoder(), ChainedFormatter::default()));
            $now = new DateTimeImmutable();
            $now = $now->setTime($now->format('H'), $now->format('i'));
            $token = $tokenBuilder
                ->issuedBy('supabase')
                ->issuedAt($now)
                ->expiresAt($now->modify('+100 year'))
                ->withClaim('role', 'anon')
                ->getToken($algorithm, $key);
            $generatedValue = $token->toString();
            break;
        case 'SUPABASESERVICE':
            $signingKey = $service->environment_variables()->where('key', 'SERVICE_PASSWORD_JWT')->first();
            if (is_null($signingKey)) {
                return;
            } else {
                $signingKey = $signingKey->value;
            }
            $key = InMemory::plainText($signingKey);
            $algorithm = new Sha256();
            $tokenBuilder = (new Builder(new JoseEncoder(), ChainedFormatter::default()));
            $now = new DateTimeImmutable();
            $now = $now->setTime($now->format('H'), $now->format('i'));
            $token = $tokenBuilder
                ->issuedBy('supabase')
                ->issuedAt($now)
                ->expiresAt($now->modify('+100 year'))
                ->withClaim('role', 'service_role')
                ->getToken($algorithm, $key);
            $generatedValue = $token->toString();
            break;
        default:
            $generatedValue = Str::random(16);
            break;
    }
    return $generatedValue;
}

function getRealtime()
{
    $envDefined = env('PUSHER_PORT');
    if (empty($envDefined)) {
        $url = Url::fromString(Request::getSchemeAndHttpHost());
        $port = $url->getPort();
        if ($port) {
            return '6001';
        } else {
            return null;
        }
    } else {
        return $envDefined;
    }
}

function validate_dns_entry(string $fqdn, Server $server)
{
    # https://www.cloudflare.com/ips-v4/#
    $cloudflare_ips = collect(['173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '172.64.0.0/13', '131.0.72.0/22']);

    $url = Url::fromString($fqdn);
    $host = $url->getHost();
    if (str($host)->contains('sslip.io')) {
        return true;
    }
    $settings = InstanceSettings::get();
    $is_dns_validation_enabled = data_get($settings, 'is_dns_validation_enabled');
    if (!$is_dns_validation_enabled) {
        return true;
    }
    $dns_servers = data_get($settings, 'custom_dns_servers');
    $dns_servers = str($dns_servers)->explode(',');
    if ($server->id === 0) {
        $ip = data_get($settings, 'public_ipv4', data_get($settings, 'public_ipv6', $server->ip));
    } else {
        $ip = $server->ip;
    }
    $found_matching_ip = false;
    $type = \PurplePixie\PhpDns\DNSTypes::NAME_A;
    foreach ($dns_servers as $dns_server) {
        try {
            ray("Checking $host on $dns_server");
            $query = new DNSQuery($dns_server);
            $results = $query->query($host, $type);
            if ($results === false || $query->hasError()) {
                ray("Error: " . $query->getLasterror());
            } else {
                foreach ($results as $result) {
                    if ($result->getType() == $type) {
                        if (ip_match($result->getData(), $cloudflare_ips->toArray(), $match)) {
                            ray("Found match in Cloudflare IPs: $match");
                            $found_matching_ip = true;
                            break;
                        }
                        if ($result->getData() === $ip) {
                            ray($host . " has IP address " . $result->getData());
                            ray($result->getString());
                            $found_matching_ip = true;
                            break;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
        }
    }
    ray("Found match: $found_matching_ip");
    return $found_matching_ip;
}

function ip_match($ip, $cidrs, &$match = null)
{
    foreach ((array) $cidrs as $cidr) {
        list($subnet, $mask) = explode('/', $cidr);
        if (((ip2long($ip) & ($mask = ~((1 << (32 - $mask)) - 1))) == (ip2long($subnet) & $mask))) {
            $match = $cidr;
            return true;
        }
    }
    return false;
}
function check_domain_usage(ServiceApplication|Application|null $resource = null, ?string $domain = null)
{
    if ($resource) {
        if ($resource->getMorphClass() === 'App\Models\Application' && $resource->build_pack === 'dockercompose') {
            $domains = data_get(json_decode($resource->docker_compose_domains, true), "*.domain");
            ray($domains);
            $domains = collect($domains);
        } else {
            $domains = collect($resource->fqdns);
        }
    } else if ($domain) {
        $domains = collect($domain);
    } else {
        throw new \RuntimeException("No resource or FQDN provided.");
    }
    $domains = $domains->map(function ($domain) {
        if (str($domain)->endsWith('/')) {
            $domain = str($domain)->beforeLast('/');
        }
        return str($domain);
    });
    $apps = Application::all();
    foreach ($apps as $app) {
        $list_of_domains = collect(explode(',', $app->fqdn))->filter(fn ($fqdn) => $fqdn !== '');
        foreach ($list_of_domains as $domain) {
            if (str($domain)->endsWith('/')) {
                $domain = str($domain)->beforeLast('/');
            }
            $naked_domain = str($domain)->value();
            if ($domains->contains($naked_domain)) {
                if (data_get($resource, 'uuid')) {
                    if ($resource->uuid !== $app->uuid) {
                        throw new \RuntimeException("Domain $naked_domain is already in use by another resource called: <br><br>{$app->name}.");
                    }
                } else if ($domain) {
                    throw new \RuntimeException("Domain $naked_domain is already in use by another resource called: <br><br>{$app->name}.");
                }
            }
        }
    }
    $apps = ServiceApplication::all();
    foreach ($apps as $app) {
        $list_of_domains = collect(explode(',', $app->fqdn))->filter(fn ($fqdn) => $fqdn !== '');
        foreach ($list_of_domains as $domain) {
            if (str($domain)->endsWith('/')) {
                $domain = str($domain)->beforeLast('/');
            }
            $naked_domain = str($domain)->value();
            if ($domains->contains($naked_domain)) {
                if (data_get($resource, 'uuid')) {
                    if ($resource->uuid !== $app->uuid) {
                        throw new \RuntimeException("Domain $naked_domain is already in use by another resource called: <br><br>{$app->name}.");
                    }
                } else if ($domain) {
                    throw new \RuntimeException("Domain $naked_domain is already in use by another resource called: <br><br>{$app->name}.");
                }
            }
        }
    }
    if ($resource) {
        $settings = InstanceSettings::get();
        if (data_get($settings, 'fqdn')) {
            $domain = data_get($settings, 'fqdn');
            if (str($domain)->endsWith('/')) {
                $domain = str($domain)->beforeLast('/');
            }
            $naked_domain = str($domain)->value();
            if ($domains->contains($naked_domain)) {
                throw new \RuntimeException("Domain $naked_domain is already in use by this Coolify instance.");
            }
        }
    }
}

function parseCommandsByLineForSudo(Collection $commands, Server $server): array
{
    $commands = $commands->map(function ($line) {
        if (!str($line)->startsWith('cd') && !str($line)->startsWith('command') && !str($line)->startsWith('echo') && !str($line)->startsWith('true')) {
            return "sudo $line";
        }
        return $line;
    });
    $commands = $commands->map(function ($line) use ($server) {
        if (Str::startsWith($line, 'sudo mkdir -p')) {
            return "$line && sudo chown -R $server->user:$server->user " . Str::after($line, 'sudo mkdir -p') . ' && sudo chmod -R o-rwx ' . Str::after($line, 'sudo mkdir -p');
        }
        return $line;
    });
    $commands = $commands->map(function ($line) {
        $line = str($line);
        if (str($line)->contains('$(')) {
            $line = $line->replace('$(', '$(sudo ');
        }
        if (str($line)->contains('||')) {
            $line = $line->replace('||', '|| sudo');
        }
        if (str($line)->contains('&&')) {
            $line = $line->replace('&&', '&& sudo');
        }
        if (str($line)->contains(' | ')) {
            $line = $line->replace(' | ', ' | sudo ');
        }
        return $line->value();
    });

    return $commands->toArray();
}
function parseLineForSudo(string $command, Server $server): string
{
    if (!str($command)->startSwith('cd') && !str($command)->startSwith('command')) {
        $command = "sudo $command";
    }
    if (Str::startsWith($command, 'sudo mkdir -p')) {
        $command =  "$command && sudo chown -R $server->user:$server->user " . Str::after($command, 'sudo mkdir -p') . ' && sudo chmod -R o-rwx ' . Str::after($command, 'sudo mkdir -p');
    }
    if (str($command)->contains('$(') || str($command)->contains('`')) {
        $command = str($command)->replace('$(', '$(sudo ')->replace('`', '`sudo ')->value();
    }
    if (str($command)->contains('||')) {
        $command = str($command)->replace('||', '|| sudo ')->value();
    }
    if (str($command)->contains('&&')) {
        $command = str($command)->replace('&&', '&& sudo ')->value();
    }

    return $command;
}

function get_public_ips()
{
    try {
        echo "Refreshing public ips!\n";
        $settings = InstanceSettings::get();
        [$first, $second] = Process::concurrently(function (Pool $pool) {
            $pool->path(__DIR__)->command('curl -4s https://ifconfig.io');
            $pool->path(__DIR__)->command('curl -6s https://ifconfig.io');
        });
        $ipv4 = $first->output();
        if ($ipv4) {
            $ipv4 = trim($ipv4);
            $validate_ipv4 = filter_var($ipv4, FILTER_VALIDATE_IP);
            if ($validate_ipv4 == false) {
                echo "Invalid ipv4: $ipv4\n";
                return;
            }
            $settings->update(['public_ipv4' => $ipv4]);
        }
        $ipv6 = $second->output();
        if ($ipv6) {
            $ipv6 = trim($ipv6);
            $validate_ipv6 = filter_var($ipv6, FILTER_VALIDATE_IP);
            if ($validate_ipv6 == false) {
                echo "Invalid ipv6: $ipv6\n";
                return;
            }
            $settings->update(['public_ipv6' => $ipv6]);
        }
    } catch (\Throwable $e) {
        echo "Error: {$e->getMessage()}\n";
    }
}
