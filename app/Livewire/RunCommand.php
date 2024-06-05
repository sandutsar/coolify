<?php

namespace App\Livewire;

use App\Models\Server;
use Livewire\Component;

class RunCommand extends Component
{
    public string $command;
    public $server;
    public $servers = [];

    protected $rules = [
        'server' => 'required',
        'command' => 'required',
    ];
    protected $validationAttributes = [
        'server' => 'server',
        'command' => 'command',
    ];

    public function mount($servers)
    {
        $this->servers = $servers;
        $this->server = $servers[0]->uuid;
    }

    public function runCommand()
    {
        $this->validate();
        try {
            $activity = remote_process([$this->command], Server::where('uuid', $this->server)->first(), ignore_errors: true);
            $this->dispatch('activityMonitor', $activity->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
