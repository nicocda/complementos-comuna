<?php

namespace Tests\Feature;

use App\Enums\ProblemKind;
use App\Enums\RepairStatus;
use App\Filament\Resources\RepairRequests\Pages\CreateRepairRequest;
use App\Filament\Resources\RepairRequests\Pages\ViewRepairRequest;
use App\Models\Driver;
use App\Models\RepairRequest;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\RepairRequestStatusChanged;
use App\Services\RepairWorkflow;
use App\Support\Permissions;
use App\Support\WhatsAppLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class RepairRequestPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        Notification::fake();
    }

    public function test_driver_creates_a_notice_from_the_form(): void
    {
        [$user, $driver] = $this->driverUser();
        $unit = Unit::factory()->create();
        $this->actingAs($user);

        Livewire::test(CreateRepairRequest::class)
            ->assertFormFieldHidden('driver_id')
            ->fillForm([
                'unit_id' => $unit->getKey(),
                'problem_kind' => ProblemKind::Breakdown->value,
                'detected_on' => today()->toDateString(),
                'problem_description' => 'Se rompió la manguera hidráulica.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $request = RepairRequest::query()->sole();
        $this->assertTrue($request->driver->is($driver));
        $this->assertSame(RepairStatus::Reported, $request->status);
    }

    public function test_form_validates_required_fields(): void
    {
        [$user] = $this->driverUser();
        $this->actingAs($user);

        Livewire::test(CreateRepairRequest::class)
            ->fillForm(['unit_id' => null, 'problem_kind' => null, 'problem_description' => 'corto'])
            ->call('create')
            ->assertHasFormErrors(['unit_id' => 'required', 'problem_kind' => 'required', 'problem_description' => 'min']);

        $this->assertSame(0, RepairRequest::query()->count());
    }

    public function test_service_errors_are_shown_on_the_form(): void
    {
        // Driver role but no linked driver record: the service refuses it.
        $this->actingAs($this->userWithRole(Permissions::ROLE_DRIVER));

        Livewire::test(CreateRepairRequest::class)
            ->fillForm([
                'unit_id' => Unit::factory()->create()->getKey(),
                'problem_kind' => ProblemKind::Breakdown->value,
                'detected_on' => today()->toDateString(),
                'problem_description' => 'Pierde aceite por el cárter.',
            ])
            ->call('create')
            ->assertHasFormErrors(['driver_id']);
    }

    public function test_supervisor_must_pick_the_driver(): void
    {
        $this->actingAs($this->userWithRole(Permissions::ROLE_SUPERVISOR));

        Livewire::test(CreateRepairRequest::class)
            ->assertFormFieldVisible('driver_id')
            ->fillForm([
                'unit_id' => Unit::factory()->create()->getKey(),
                'problem_kind' => ProblemKind::Breakdown->value,
                'detected_on' => today()->toDateString(),
                'problem_description' => 'Pierde aceite por el cárter.',
            ])
            ->call('create')
            ->assertHasFormErrors(['driver_id' => 'required']);
    }

    public function test_actions_follow_role_and_status(): void
    {
        [$driverUser, $driver] = $this->driverUser();
        $supervisor = $this->userWithRole(Permissions::ROLE_SUPERVISOR);
        $superior = $this->userWithRole(Permissions::ROLE_SUPERIOR);
        $request = RepairRequest::factory()->create(['driver_id' => $driver->getKey()]);

        $this->actingAs($driverUser);
        Livewire::test(ViewRepairRequest::class, ['record' => $request->getKey()])
            ->assertActionHidden('assess')
            ->assertActionHidden('approve')
            ->assertActionHidden('complete');

        $this->actingAs($superior);
        Livewire::test(ViewRepairRequest::class, ['record' => $request->getKey()])
            ->assertActionHidden('approve')
            ->assertActionHidden('assess');

        $this->actingAs($supervisor);
        Livewire::test(ViewRepairRequest::class, ['record' => $request->getKey()])
            ->assertActionVisible('assess')
            ->assertActionHidden('approve')
            ->callAction('assess', data: [
                'urgency' => 'alta',
                'inspection_notes' => 'Revisado en maestranza.',
                'estimated_cost' => 50000,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Informe elevado a superiores');

        $this->assertSame(RepairStatus::PendingApproval, $request->fresh()->status);

        $this->actingAs($superior);
        Livewire::test(ViewRepairRequest::class, ['record' => $request->getKey()])
            ->assertActionVisible('approve')
            ->callAction('reject', data: ['decision_notes' => ''])
            ->assertHasActionErrors(['decision_notes' => 'required']);
        $this->assertSame(RepairStatus::PendingApproval, $request->fresh()->status);

        Livewire::test(ViewRepairRequest::class, ['record' => $request->getKey()])
            ->callAction('approve', data: ['decision_notes' => 'OK'])
            ->assertHasNoActionErrors();

        $this->assertSame(RepairStatus::Approved, $request->fresh()->status);

        $this->actingAs($supervisor);
        Livewire::test(ViewRepairRequest::class, ['record' => $request->getKey()])
            ->callAction('complete', data: [
                'provider' => 'Taller Central',
                'work_performed' => 'Cambio de manguera',
                'final_cost' => 61000,
                'completed_on' => today()->toDateString(),
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(RepairStatus::Completed, $request->fresh()->status);
    }

    public function test_upload_signed_form_stores_private_file(): void
    {
        Storage::fake('local');
        $supervisor = $this->userWithRole(Permissions::ROLE_SUPERVISOR);
        $request = RepairRequest::factory()->create();
        $this->actingAs($supervisor);

        Livewire::test(ViewRepairRequest::class, ['record' => $request->getKey()])
            ->callAction('uploadSignedForm', data: [
                'signed_form' => UploadedFile::fake()->create('firmado.pdf', 200, 'application/pdf'),
            ])
            ->assertHasNoActionErrors();

        $path = $request->fresh()->signed_form_path;
        $this->assertNotNull($path);
        $this->assertStringStartsWith(RepairWorkflow::SIGNED_FORMS_DIRECTORY.'/', $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_upload_rejects_disallowed_file_types(): void
    {
        Storage::fake('local');
        $this->actingAs($this->userWithRole(Permissions::ROLE_SUPERVISOR));
        $request = RepairRequest::factory()->create();

        Livewire::test(ViewRepairRequest::class, ['record' => $request->getKey()])
            ->callAction('uploadSignedForm', data: [
                'signed_form' => UploadedFile::fake()->create('script.php', 1, 'application/x-php'),
            ])
            ->assertHasActionErrors(['signed_form']);

        $this->assertNull($request->fresh()->signed_form_path);
    }

    public function test_whatsapp_link_contains_summary_but_not_description(): void
    {
        $request = RepairRequest::factory()->create(['problem_description' => 'Detalle interno sensible']);

        $url = WhatsAppLink::forRepairRequest($request);

        $this->assertStringStartsWith('https://wa.me/?text=', $url);
        $text = rawurldecode(substr($url, strlen('https://wa.me/?text=')));
        $this->assertStringContainsString($request->formattedNumber(), $text);
        $this->assertStringContainsString('/admin/reparaciones/'.$request->getKey(), $text);
        $this->assertStringNotContainsString('Detalle interno sensible', $text);
    }

    public function test_notification_renders_for_database_and_mail(): void
    {
        $supervisor = $this->userWithRole(Permissions::ROLE_SUPERVISOR);
        $request = RepairRequest::factory()->create();
        $notification = new RepairRequestStatusChanged($request, User::factory()->create(['name' => 'Ana']));

        $database = $notification->toDatabase($supervisor);
        $mail = $notification->toMail($supervisor);

        $this->assertStringContainsString($request->formattedNumber(), $database['title']);
        $this->assertSame($notification->title(), $mail->subject);
        $this->assertStringContainsString('/admin/reparaciones/'.$request->getKey(), $mail->actionUrl);
    }
}
