<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Illuminate\Support\Str;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Rollback extends Component
{
    public Application $application;
    public $images = [];
    public string|null $current;
    public array $parameters;

    public function mount()
    {
        $this->parameters = get_route_parameters();
    }
    public function rollbackImage($commit)
    {
        $deployment_uuid = new Cuid2(7);

        queue_application_deployment(
            application: $this->application,
            deployment_uuid: $deployment_uuid,
            commit: $commit,
            rollback: true,
            force_rebuild: false,
        );
        return redirect()->route('project.application.deployment.show', [
            'project_uuid' => $this->parameters['project_uuid'],
            'application_uuid' => $this->parameters['application_uuid'],
            'deployment_uuid' => $deployment_uuid,
            'environment_name' => $this->parameters['environment_name'],
        ]);
    }

    public function loadImages($showToast = false)
    {
        try {
            $image = $this->application->docker_registry_image_name ?? $this->application->uuid;
            if ($this->application->destination->server->isFunctional()) {
                $output = instant_remote_process([
                    "docker inspect --format='{{.Config.Image}}' {$this->application->uuid}",
                ], $this->application->destination->server, throwError: false);
                $current_tag = Str::of($output)->trim()->explode(":");
                $this->current = data_get($current_tag, 1);

                $output = instant_remote_process([
                    "docker images --format '{{.Repository}}#{{.Tag}}#{{.CreatedAt}}'",
                ], $this->application->destination->server);
                $this->images = Str::of($output)->trim()->explode("\n")->filter(function ($item) use ($image) {
                    return Str::of($item)->contains($image);
                })->map(function ($item) {
                    $item = Str::of($item)->explode('#');
                    if ($item[1] === $this->current) {
                        // $is_current = true;
                    }
                    return [
                        'tag' => $item[1],
                        'created_at' => $item[2],
                        'is_current' => $is_current ?? null,
                    ];
                })->toArray();
            }
            $showToast && $this->dispatch('success', 'Images loaded.');
            return [];
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
