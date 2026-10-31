<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Livewire\CarePlan\CarePlanUpdate;
use App\Livewire\Components\FlashMessage;
use App\Livewire\Components\XMessage;
use App\Models\CarePlan;
use App\Repositories\CarePlanRepository;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ReviewFlashMessagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_ajax_renders_each_session_message_in_the_action_response_without_events(): void
    {
        foreach (['error', 'success', 'info', 'warning'] as $type) {
            $message = 'Notification: '.$type;
            $component = Livewire::test(ReviewFlashHarness::class)->call('notify', $type, $message)
                ->assertSee($message)->assertNotDispatched('flashMessage');

            $this->assertSame([], $component->effects['dispatches'] ?? []);
            $this->assertStringContainsString($message, $component->effects['html']);
            $this->assertFalse(Session::has($type));
            Livewire::test(FlashMessage::class)->assertDontSee($message);
            $component->call('$refresh')->assertDontSee($message);
        }
    }

    public function test_identical_messages_in_the_same_second_remount_the_toast(): void
    {
        $this->freezeTime();
        $component = Livewire::test(ReviewFlashHarness::class)->call('notify')
            ->assertSee('Check the draft');
        $firstChildren = $component->snapshot['memo']['children'];

        $component->call('notify')->assertSee('Check the draft')->assertNotDispatched('flashMessage');

        $this->assertCount(1, $firstChildren);
        $this->assertCount(1, $component->snapshot['memo']['children']);
        $this->assertNotSame($firstChildren, $component->snapshot['memo']['children']);
    }

    public function test_redirect_preserves_flash_until_the_destination_renders_it_once(): void
    {
        Livewire::test(ReviewFlashHarness::class)->call('saveAndRedirect')
            ->assertRedirect('/')->assertNotDispatched('flashMessage');
        $this->assertSame('Saved', Session::get('info'));

        Livewire::test(ReviewFlashHarness::class)->assertSee('Saved');
        Livewire::test(FlashMessage::class)->assertDontSee('Saved');
        Livewire::test(ReviewFlashHarness::class)->assertDontSee('Saved');
    }

    public function test_session_toast_has_no_server_event_listener_and_escapes_messages(): void
    {
        Session::flash('error', '<script>alert("error")</script>');
        $component = Livewire::test(XMessage::class, ['consumeMessages' => true])
            ->assertSee('<script>alert("error")</script>')
            ->assertDontSeeHtml('<script>alert("error")</script>')
            ->assertSet('listenAsync', false);

        $this->assertSame([], $component->effects['listeners'] ?? []);
        $this->assertFalse(Session::has('error'));
    }

    public function test_session_reader_consumes_messages_by_default(): void
    {
        Session::flash('error', 'Existing error');
        Livewire::test(XMessage::class)->assertSee('Existing error');

        $this->assertFalse(Session::has('error'));
    }

    public function test_delete_action_keeps_its_message_for_the_redirect(): void
    {
        foreach (['draft' => 'success', 'active' => 'error'] as $status => $type) {
            $plan = Mockery::mock(CarePlan::class)->makePartial();
            $plan->exists = true;
            $plan->id = 1;
            $plan->status = $status;
            $plan->setRelation('encounter', null);
            $repository = Mockery::mock(CarePlanRepository::class);
            $repository->shouldReceive('deleteDraft')->once()->with(1)->andReturn($status === 'draft');
            \Illuminate\Support\Facades\Gate::define('update', static fn (?\App\Models\User $user) => true);
            $component = new ReviewCarePlanDeleteHarness();
            $component->carePlan = $plan;
            $component->personId = 1;

            $component->delete($repository);

            $this->assertSame('persons.care-plans', $component->redirectedRoute);
            $message = $status === 'draft'
                ? __('Чернетку плану лікування успішно видалено.')
                : __('Можна видаляти лише чернетки планів лікування.');
            $this->assertSame($message, Session::get($type));
            Livewire::test(ReviewFlashHarness::class)->assertSee($message);
            Livewire::test(FlashMessage::class)->assertDontSee($message);
        }
    }
    public function test_page_and_layout_render_each_notification_once(): void
    {
        foreach (['error', 'success', 'info', 'warning', 'status'] as $type) {
            $message = 'Unique notification: '.$type;
            Session::flash($type, $message);

            $html = Blade::render(<<<'BLADE'
                <div>
                    <livewire:components.x-message />
                    <livewire:components.x-message />
                    <livewire:components.flash-message />
                </div>
                BLADE);

            $this->assertSame(1, substr_count($html, $message));
            $this->assertSame(1, substr_count($html, 'aria-label="Close"'));
            $this->assertFalse(Session::has($type));
            Livewire::test(XMessage::class)->assertDontSee($message);
        }
    }

    public function test_all_pending_types_are_consumed_together(): void
    {
        foreach (['error', 'success', 'info', 'warning', 'status'] as $type) {
            Session::flash($type, 'Pending '.$type);
        }

        $component = Livewire::test(XMessage::class);

        foreach (['error', 'success', 'info', 'warning', 'status'] as $type) {
            $component->assertSee('Pending '.$type);
            $this->assertFalse(Session::has($type));
        }

        $this->assertSame(1, substr_count($component->html(), 'aria-label="Close"'));
        $component->call('$refresh')->assertDontSee('Pending');
    }

    public function test_guest_layout_displays_session_termination_once(): void
    {
        Session::flash('error', __('auth.session_terminated'));

        $html = Blade::render(<<<'BLADE'
            @extends('layouts.guest')
            @section('content')
                <livewire:components.x-message />
            @endsection
            BLADE);

        $this->assertSame(1, substr_count($html, e(__('auth.session_terminated'))));
        $this->assertSame(1, substr_count($html, 'aria-label="Close"'));
        $this->assertFalse(Session::has('error'));
    }

    public function test_layout_displays_flash_when_page_has_no_message_component(): void
    {
        Session::flash('status', 'Password reset successfully');

        $html = Blade::render(<<<'BLADE'
            @extends('layouts.guest')
            @section('content')
                <div>Page without notifications</div>
            @endsection
            BLADE);

        $this->assertSame(1, substr_count($html, 'Password reset successfully'));
        $this->assertFalse(Session::has('status'));
    }

    public function test_existing_event_has_only_one_consumer(): void
    {
        $payload = ['message' => 'Legacy event notification', 'type' => 'warning'];
        $component = Livewire::test(XMessage::class, ['listenAsync' => true])
            ->dispatch('flashMessage', $payload)
            ->assertSee($payload['message'])
            ->assertSet('notificationId', 1);

        $this->assertSame(1, substr_count($component->html(), $payload['message']));
        $sessionComponent = Livewire::test(XMessage::class);
        $this->assertSame([], $sessionComponent->effects['listeners'] ?? []);
        $sessionComponent->assertDontSeeHtml('flash-message.window')
            ->assertDontSeeHtml('showDynamicMessage');
    }

    public function test_legacy_component_name_uses_the_shared_message_view(): void
    {
        Livewire::test(FlashMessage::class)
            ->dispatch('flashMessage', ['type' => 'info', 'message' => 'Legacy integration'])
            ->assertSee('Legacy integration')
            ->assertViewIs('livewire.components.x-message');
    }

    public function test_repeated_legacy_events_restart_the_same_notification(): void
    {
        $payload = ['message' => 'Saved twice', 'type' => 'success'];
        $component = Livewire::test(XMessage::class, ['listenAsync' => true])
            ->dispatch('flashMessage', $payload)
            ->assertSet('notificationId', 1);
        $id = $component->snapshot['memo']['id'];
        $component->assertSeeHtml('wire:key="message-'.$id.'-1"');

        $component->dispatch('flashMessage', $payload)
            ->assertSet('notificationId', 2)
            ->assertSeeHtml('wire:key="message-'.$id.'-2"');
        $this->assertSame(1, substr_count($component->html(), 'aria-label="Close"'));
    }

    public function test_remounted_session_notifications_have_distinct_alpine_keys(): void
    {
        Session::flash('success', 'First notification');
        $first = Livewire::test(XMessage::class);
        $firstId = $first->snapshot['memo']['id'];
        $first->assertSeeHtml('wire:key="message-'.$firstId.'-0"');

        Session::flash('success', 'First notification');
        $second = Livewire::test(XMessage::class);
        $secondId = $second->snapshot['memo']['id'];
        $second->assertSeeHtml('wire:key="message-'.$secondId.'-0"');
        $this->assertNotSame($firstId, $secondId);
    }

    public function test_session_messages_and_legacy_validation_errors_share_one_stack(): void
    {
        $component = Livewire::test(XMessage::class, ['listenAsync' => true]);
        Session::flash('info', 'Pending session message');

        $component->dispatch('flashMessage', [
            'errors' => ['Invalid field', '<script>alert("error")</script>'],
            'type' => 'error',
        ])->assertSee('Pending session message')
            ->assertSee('Invalid field')
            ->assertDontSeeHtml('<script>alert("error")</script>');

        $this->assertSame(1, substr_count($component->html(), 'aria-label="Close"'));
        $this->assertFalse(Session::has('info'));
        $component->call('$refresh')->assertDontSee('Pending session message');
    }
}

class ReviewCarePlanDeleteHarness extends CarePlanUpdate
{
    public ?string $redirectedRoute = null;

    public function redirectRoute($name, $parameters = [], $absolute = true, $navigate = false)
    {
        $this->redirectedRoute = $name;
    }
}

class ReviewFlashHarness extends Component
{
    public function notify(string $type = 'warning', string $message = 'Check the draft'): void
    {
        Session::flash($type, $message);
    }

    public function saveAndRedirect(): void
    {
        Session::flash('info', 'Saved');
        $this->redirect('/', navigate: true);
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
            </div>
            BLADE;
    }
}
