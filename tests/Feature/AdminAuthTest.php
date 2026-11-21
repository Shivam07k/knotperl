<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    public function test_protected_routes_redirect_and_webhook_does_not(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('images.index'))->assertRedirect(route('login'));
        $this->post(route('webhook.build'), [], ['HTTP_X_CMS_SIGNATURE' => 'bad'])
            ->assertStatus(401);
    }

    public function test_login_logout_and_missing_password_hash(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        config()->set('cms.admin_password_hash', $hash);

        $this->post(route('login.attempt'), ['password' => 'wrong'])
            ->assertSessionHasErrors('password');
        $this->post(route('login.attempt'), ['password' => 'secret'])
            ->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->get(route('dashboard'))->assertRedirect(route('login'));

        config()->set('cms.admin_password_hash', null);
        $this->get(route('login'))->assertSee('ADMIN_PASSWORD_HASH');
    }

    public function test_only_editable_tabs_are_reachable_by_authenticated_operators(): void
    {
        config()->set('cms.admin_password_hash', password_hash('secret', PASSWORD_DEFAULT));
        $this->withSession(['admin_authenticated' => true])
            ->get('/content/Products')->assertOk();
        $this->withSession(['admin_authenticated' => true])
            ->get('/content/SEO')->assertNotFound();
    }
}
