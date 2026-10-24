<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Livewire\CarePlan\CarePlanUpdate;
use App\Livewire\Components\FlashMessage;
use App\Livewire\Components\XMessage;
use App\Models\CarePlan;
use App\Repositories\CarePlanRepository;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ReviewFlashMessagesTest extends TestCase
{
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

    public function test_legacy_session_reader_keeps_its_existing_default_behavior(): void
    {
        Session::flash('error', 'Existing error');
        Livewire::test(XMessage::class)->assertSee('Existing error');

        $this->assertSame('Existing error', Session::get('error'));
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
