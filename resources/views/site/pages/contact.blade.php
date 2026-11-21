@extends('site.layouts.app')

@section('content')
    <header class="page-header">
        <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="/">Home</a><span>/</span><span>{{ $page['title'] ?? 'Contact Us' }}</span></nav>
        <h1>{{ $page['title'] ?? 'Contact Us' }}</h1>
    </header>
    <div class="prose page-content">@clean($page['content'] ?? '')</div>
    <section class="section">
        <div class="section-inner contact-layout">
            @include('site.partials.contact-details', ['contact' => $site['contact']])
            <form class="contact-form" data-contact-form>
                <label for="contact-name">Name</label>
                <input id="contact-name" name="name" type="text" required>
                <label for="contact-email">Email</label>
                <input id="contact-email" name="email" type="email" required>
                <label for="contact-message">Message</label>
                <textarea id="contact-message" name="message" rows="5" required></textarea>
                <button class="button" type="submit">Send enquiry</button>
                @if(empty($site['settings']['form_endpoint']))
                    <small>Connect a form provider to receive submissions.</small>
                @endif
            </form>
        </div>
    </section>
    @include('site.partials.sections', ['sections' => $site['sections']['contact-us'] ?? [], 'page' => $page])
@endsection
