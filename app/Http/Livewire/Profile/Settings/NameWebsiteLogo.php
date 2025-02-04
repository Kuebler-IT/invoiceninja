<?php

namespace App\Http\Livewire\Profile\Settings;

use Livewire\Component;

class NameWebsiteLogo extends Component
{
    public $profile;

    public $name;
    public $website;
    public $phone;

    public $saved;

    public $rules = [
        'name' => ['sometimes', 'min:3'],
        'website' => ['sometimes'],
        'phone' => ['sometimes', 'string', 'max:255'],
    ];

    public function mount()
    {
        $this->fill([
            'profile' => auth()->user('contact')->client,
            'name' => auth()->user('contact')->client->present()->name,
            'website' => auth()->user('contact')->client->present()->website,
            'phone' => auth()->user('contact')->client->present()->phone,
            'saved' => ctrans('texts.save'),
        ]);
    }

    public function render()
    {
        return render('profile.settings.name-website-logo');
    }

    public function submit()
    {
        $data = $this->validate($this->rules);

        $this->profile->name = $data['name'];
        $this->profile->website = $data['website'];
        $this->profile->phone = $data['phone'];

        $this->profile->save();

        $this->saved = ctrans('texts.saved_at', ['time' => now()->toTimeString()]);
    }
}
