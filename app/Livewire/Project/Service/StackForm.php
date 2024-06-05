<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use Illuminate\Support\Collection;
use Livewire\Component;

class StackForm extends Component
{
    public Service $service;
    public Collection $fields;
    protected $listeners = ["saveCompose"];
    public $rules = [
        'service.docker_compose_raw' => 'required',
        'service.docker_compose' => 'required',
        'service.name' => 'required',
        'service.description' => 'nullable',
        'service.connect_to_docker_network' => 'nullable',
    ];
    public $validationAttributes = [];
    public function mount()
    {
        $this->fields = collect([]);
        $extraFields = $this->service->extraFields();
        foreach ($extraFields as $serviceName => $fields) {
            foreach ($fields as $fieldKey => $field) {
                $key = data_get($field, 'key');
                $value = data_get($field, 'value');
                $rules = data_get($field, 'rules', 'nullable');
                $isPassword = data_get($field, 'isPassword');
                $this->fields->put($key, [
                    "serviceName" => $serviceName,
                    "key" => $key,
                    "name" => $fieldKey,
                    "value" => $value,
                    "isPassword" => $isPassword,
                    "rules" => $rules
                ]);

                $this->rules["fields.$key.value"] = $rules;
                $this->validationAttributes["fields.$key.value"] = $fieldKey;
            }
        }
        $this->fields = $this->fields->sortBy('name');
    }
    public function saveCompose($raw)
    {
        $this->service->docker_compose_raw = $raw;
        $this->submit();
    }
    public function instantSave()
    {
        $this->service->save();
        $this->dispatch('success', 'Service settings saved.');
    }

    public function submit()
    {
        try {
            $this->validate();
            $isValid = validateComposeFile($this->service->docker_compose_raw, $this->service->server->id);
            if ($isValid !== 'OK') {
                throw new \Exception("Invalid docker-compose file.\n$isValid");
            }
            $this->service->save();
            $this->service->saveExtraFields($this->fields);
            $this->service->parse();
            $this->service->refresh();
            $this->service->saveComposeConfigs();
            $this->dispatch('refreshStacks');
            $this->dispatch('refreshEnvs');
            $this->dispatch('success', 'Service saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            if (is_null($this->service->config_hash)) {
                ray('asdf');
                $this->service->isConfigurationChanged(true);
            } else {
                $this->dispatch('configurationChanged');
            }
        }
    }
    public function render()
    {
        return view('livewire.project.service.stack-form');
    }
}
