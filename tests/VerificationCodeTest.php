<?php

namespace NextApps\VerificationCode\Tests;

use Illuminate\Support\Str;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Notifications\AnonymousNotifiable;
use NextApps\VerificationCode\Models\VerificationCode;
use NextApps\VerificationCode\Exceptions\InvalidClassException;
use NextApps\VerificationCode\Notifications\VerificationCodeCreated;
use NextApps\VerificationCode\VerificationCode as VerificationCodeFacade;

class VerificationCodeTest extends TestCase
{
    /** @test */
    public function it_sends_mail_to_verifiable()
    {
        $email = $this->faker->safeEmail;

        VerificationCodeFacade::send($email);

        $this->assertNotNull(VerificationCode::where('verifiable', $email)->first());

        Notification::assertSentTo(
            new AnonymousNotifiable,
            VerificationCodeCreated::class,
            function ($notification, $channels, $notifiable) use ($email) {
                return $notifiable->routes['mail'] === $email;
            }
        );
    }

    /** @test */
    public function it_queues_mail_to_verifiable()
    {
        $email = $this->faker->safeEmail;
        $queue = Str::random();

        config()->set('verification-code.queue', $queue);

        VerificationCodeFacade::send($email);

        $this->assertNotNull(VerificationCode::where('verifiable', $email)->first());

        Notification::assertSentTo(
            new AnonymousNotifiable,
            VerificationCodeCreated::class,
            function ($notification, $channels, $notifiable) use ($email, $queue) {
                return $notifiable->routes['mail'] === $email && $notification->queue === $queue;
            }
        );
    }

    /** @test */
    public function it_does_not_queue_mail_to_verifiable()
    {
        $email = $this->faker->safeEmail;

        config()->set('verification-code.queue', null);

        VerificationCodeFacade::send($email);

        $this->assertNotNull(VerificationCode::where('verifiable', $email)->first());

        Notification::assertSentTo(
            new AnonymousNotifiable,
            VerificationCodeCreated::class,
            function ($notification, $channels, $notifiable) use ($email) {
                return $notifiable->routes['mail'] === $email && $notification->queue === null;
            }
        );
    }

    /** @test */
    public function it_sends_no_mail_to_test_verifiable()
    {
        $email = $this->faker->safeEmail;

        config()->set('verification-code.test_verifiables', [$email]);

        VerificationCodeFacade::send($email);

        $this->assertNull(VerificationCode::where('verifiable', $email)->first());

        Notification::assertNothingSent();
    }

    /** @test */
    public function it_deletes_other_codes_of_verifiable_after_send_mail()
    {
        $email = $this->faker->safeEmail;

        $oldVerificationCodes = factory(VerificationCode::class, 3)->create([
            'verifiable' => $email,
        ]);

        VerificationCodeFacade::send($email);

        $dbOldVerificationCodes = VerificationCode::find($oldVerificationCodes->pluck('id')->toArray());

        $this->assertCount(0, $dbOldVerificationCodes);
    }

    /** @test */
    public function it_returns_true_if_valid_code()
    {
        $verifiable = $this->faker->randomElement([$this->faker->safeEmail]);

        $verificationCode = factory(VerificationCode::class)->create([
            'code' => ($code = Str::random()),
            'verifiable' => $verifiable,
        ]);

        $this->assertTrue(VerificationCodeFacade::verify($code, $verifiable));

        $this->assertNull(VerificationCode::find($verificationCode->id));
    }

    /** @test */
    public function it_returns_false_if_invalid_code()
    {
        $verifiable = $this->faker->randomElement([$this->faker->safeEmail]);

        $verificationCode = factory(VerificationCode::class)->create([
            'verifiable' => $verifiable,
        ]);

        $this->assertFalse(VerificationCodeFacade::verify(Str::random(), $verifiable));

        $this->assertNotNull(VerificationCode::find($verificationCode->id));
    }

    /** @test */
    public function it_returns_false_if_expired_code()
    {
        $verifiable = $this->faker->randomElement([$this->faker->safeEmail]);

        $verificationCode = factory(VerificationCode::class)->state('expired')->create([
            'code' => ($code = Str::random()),
            'verifiable' => $verifiable,
        ]);

        $this->assertFalse(VerificationCodeFacade::verify($code, $verifiable));

        $this->assertNotNull(VerificationCode::find($verificationCode->id));
    }

    /** @test */
    public function it_returns_true_if_test_verifiable_with_test_code()
    {
        $verifiable = $this->faker->randomElement([$this->faker->safeEmail]);

        config()->set('verification-code.test_verifiables', [$verifiable]);
        config()->set('verification-code.test_code', ($code = Str::random()));

        $this->assertTrue(VerificationCodeFacade::verify($code, $verifiable));
    }

    /** @test */
    public function it_returns_false_if_test_verifiable_with_invalid_test_code()
    {
        $verifiable = $this->faker->randomElement([$this->faker->safeEmail]);

        config()->set('verification-code.test_verifiables', [$verifiable]);
        config()->set('verification-code.test_code', Str::random());

        $this->assertFalse(VerificationCodeFacade::verify(Str::random(), $verifiable));
    }

    /** @test */
    public function it_returns_false_if_test_verifiable_with_empty_test_code()
    {
        $verifiable = $this->faker->randomElement([$this->faker->safeEmail]);

        config()->set('verification-code.test_verifiables', [$verifiable]);
        config()->set('verification-code.test_code', '');

        $this->assertFalse(VerificationCodeFacade::verify('', $verifiable));
    }

    /** @test */
    public function it_works_if_notification_config_value_is_empty()
    {
        $email = $this->faker->safeEmail;

        VerificationCodeFacade::send($email);

        $this->assertNotNull(VerificationCode::where('verifiable', $email)->first());

        Notification::assertSentTo(
            new AnonymousNotifiable,
            VerificationCodeCreated::class,
            function ($notification, $channels, $notifiable) use ($email) {
                return $notifiable->routes['mail'] === $email;
            }
        );
    }

    /** @test */
    public function it_throws_an_error_if_notification_does_not_extend_the_verification_notification_class()
    {
        $this->expectException(InvalidClassException::class);
        $this->expectExceptionMessage('The notification should extend the VerificationCodeCreatedInterface.');
        $email = $this->faker->safeEmail;

        config()->set('verification-code.notification', WrongNotification::class);

        VerificationCodeFacade::send($email);
    }
}

class WrongNotification extends Notification
{
}
