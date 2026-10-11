<?php

namespace Database\Seeders;

use App\Actions\Tickets\AddComment;
use App\Actions\Tickets\AssignTicket;
use App\Actions\Tickets\ChangeTicketStatus;
use App\Actions\Tickets\CreateTicket;
use App\Enums\TicketActivityType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use LogicException;

class DemoTicketSeeder extends Seeder
{
    /** @var list<array{string, TicketStatus, TicketPriority, string|null}> */
    public const TICKETS = [
        ['Accès au compte client', TicketStatus::New, TicketPriority::Normal, null],
        ['Demande de facture', TicketStatus::New, TicketPriority::Low, null],
        ['Service indisponible', TicketStatus::New, TicketPriority::Urgent, null],
        ['Export des contacts', TicketStatus::InProgress, TicketPriority::Normal, 'alex'],
        ['Paiement refusé', TicketStatus::InProgress, TicketPriority::High, 'sam'],
        ['Adresse de livraison', TicketStatus::InProgress, TicketPriority::Low, 'alex'],
        ['Import incomplet', TicketStatus::InProgress, TicketPriority::High, 'sam'],
        ['Justificatif manquant', TicketStatus::WaitingCustomer, TicketPriority::Normal, 'alex'],
        ['Informations de commande', TicketStatus::WaitingCustomer, TicketPriority::Normal, 'sam'],
        ['Double notification', TicketStatus::Resolved, TicketPriority::Normal, 'alex'],
        ['Erreur de connexion', TicketStatus::Resolved, TicketPriority::High, 'sam'],
        ['Mise à jour du profil', TicketStatus::Closed, TicketPriority::Low, 'alex'],
    ];

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException('Les tickets de démonstration sont réservés à local et testing.');
        }
        DB::transaction(function (): void {
            $creator = User::where('email', 'admin@deskops.example')->firstOrFail();
            if (Ticket::exists()) {
                foreach (self::TICKETS as $index => [$title]) {
                    $creation = Ticket::find($index + 1)?->activities()->where('type', TicketActivityType::TicketCreated)->first();
                    if ($creation === null || $creation->actor_id !== $creator->id
                        || ($creation->changes['title']['to'] ?? null) !== $title
                        || ($creation->changes['requester_email']['to'] ?? null) !== 'client'.($index + 1).'@deskops.example'
                        || $creation->created_at->toDateTimeString() !== $this->createdAt($index)->toDateTimeString()) {
                        throw new LogicException('Fixture refusée : initialiser les tickets sur une base de démonstration neuve. Aucune donnée existante ne sera écrasée.');
                    }
                }

                return;
            }
            $sequenceUsed = DB::getDriverName() === 'pgsql'
                ? DB::selectOne('SELECT is_called FROM tickets_id_seq')->is_called
                : (int) DB::table('sqlite_sequence')->where('name', 'tickets')->value('seq') > 0;
            if ($sequenceUsed) {
                throw new LogicException('Fixture refusée : les identifiants de cette base ont déjà été utilisés.');
            }
            foreach (self::TICKETS as $index => [$title, $status, $priority, $agentName]) {
                $createdAt = $this->createdAt($index);
                $number = $index + 1;
                $ticket = Date::withTestNow($createdAt, fn (): Ticket => app(CreateTicket::class)->execute($creator, [
                    'title' => $title, 'description' => 'Demande de démonstration : '.$title.'. Merci de vérifier et de proposer une solution.',
                    'requester_name' => 'Client '.$number, 'requester_email' => 'client'.$number.'@deskops.example', 'priority' => $priority->value,
                ]));
                if ($ticket->id !== $number) {
                    throw new LogicException('Fixture refusée : les identifiants de cette base ont déjà été utilisés.');
                }
                $agent = $agentName === null ? null : User::where('email', $agentName.'@deskops.example')->firstOrFail();
                if ($agent !== null) {
                    $ticket = Date::withTestNow($createdAt->addMinute(), fn (): Ticket => app(AssignTicket::class)->execute($creator, $ticket, ['version' => $ticket->version, 'assigned_to' => $agent->id]));
                    $ticket = $this->transition($creator, $ticket, TicketStatus::InProgress, $createdAt->addMinutes(2));
                    if ($status !== TicketStatus::InProgress) {
                        $ticket = $this->transition($creator, $ticket, $status === TicketStatus::Closed ? TicketStatus::Resolved : $status, $createdAt->addMinutes(3));
                    }
                }
                $ticket = Date::withTestNow($createdAt->addMinutes(5), fn (): Ticket => app(AddComment::class)->execute($creator, $ticket, ['version' => $ticket->version, 'body' => 'Demande enregistrée dans le support.']));
                $ticket = Date::withTestNow($createdAt->addMinutes(6), fn (): Ticket => app(AddComment::class)->execute($agent ?? $creator, $ticket, ['version' => $ticket->version, 'body' => 'Suivi de démonstration ajouté.']));
                if ($status === TicketStatus::Closed) {
                    $this->transition($creator, $ticket, TicketStatus::Closed, $createdAt->addMinutes(7));
                }
            }
        });
    }

    private function createdAt(int $index): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-01-15 09:00:00', 'UTC')->addMinutes($index * 30);
    }

    private function transition(User $actor, Ticket $ticket, TicketStatus $target, CarbonImmutable $time): Ticket
    {
        return Date::withTestNow($time, fn (): Ticket => app(ChangeTicketStatus::class)->execute($actor, $ticket, [
            'version' => $ticket->version, 'status' => $target->value,
            'reason' => $ticket->status->requiresReasonFor($target) ? 'Scénario de démonstration' : null,
        ]));
    }
}
