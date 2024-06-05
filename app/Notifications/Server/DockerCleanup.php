<?php

namespace App\Notifications\Server;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class DockerCleanup extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;
    public function __construct(public Server $server, public string $message)
    {
    }

    public function via(object $notifiable): array
    {
        $channels = [];
        // $isEmailEnabled = isEmailEnabled($notifiable);
        $isDiscordEnabled = data_get($notifiable, 'discord_enabled');
        $isTelegramEnabled = data_get($notifiable, 'telegram_enabled');

        if ($isDiscordEnabled) {
            $channels[] = DiscordChannel::class;
        }
        // if ($isEmailEnabled) {
        //     $channels[] = EmailChannel::class;
        // }
        if ($isTelegramEnabled) {
            $channels[] = TelegramChannel::class;
        }
        return $channels;
    }

    // public function toMail(): MailMessage
    // {
    //     $mail = new MailMessage();
    //     $mail->subject("Coolify: Server ({$this->server->name}) high disk usage detected!");
    //     $mail->view('emails.high-disk-usage', [
    //         'name' => $this->server->name,
    //         'disk_usage' => $this->disk_usage,
    //         'threshold' => $this->cleanup_after_percentage,
    //     ]);
    //     return $mail;
    // }

    public function toDiscord(): string
    {
        $message = "Coolify: Server '{$this->server->name}' cleanup job done!\n\n{$this->message}";
        return $message;
    }
    public function toTelegram(): array
    {
        return [
            "message" => "Coolify: Server '{$this->server->name}' cleanup job done!\n\n{$this->message}"
        ];
    }
}
