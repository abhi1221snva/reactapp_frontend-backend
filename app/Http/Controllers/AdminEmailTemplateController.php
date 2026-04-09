<?php

namespace App\Http\Controllers;

use App\Services\EmailTemplateService;
use App\Services\SystemMailerService;
use App\Model\Master\SystemEmailTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminEmailTemplateController extends Controller
{
    private EmailTemplateService $service;

    public function __construct()
    {
        $this->service = new EmailTemplateService();
    }

    // ── List all templates ──────────────────────────────────────────────────

    public function index()
    {
        $templates = $this->service->getAll();

        return response()->json([
            'success' => true,
            'data'    => $templates,
        ]);
    }

    // ── Show single template ────────────────────────────────────────────────

    public function show($id)
    {
        $template = SystemEmailTemplate::find($id);
        if (!$template) {
            return response()->json(['success' => false, 'message' => 'Template not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $template,
        ]);
    }

    // ── Create new template ─────────────────────────────────────────────────

    public function store(Request $request)
    {
        $this->validate($request, [
            'template_key'  => 'required|string|max:50|alpha_dash',
            'template_name' => 'required|string|max:100',
            'subject'       => 'required|string|max:255',
            'body_html'     => 'required|string',
            'placeholders'  => 'sometimes|array',
        ]);

        // Ensure unique key
        if (SystemEmailTemplate::where('template_key', $request->input('template_key'))->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'A template with this key already exists.',
            ], 422);
        }

        $template = SystemEmailTemplate::create([
            'template_key'  => $request->input('template_key'),
            'template_name' => $request->input('template_name'),
            'subject'       => $request->input('subject'),
            'body_html'     => $request->input('body_html'),
            'placeholders'  => $request->has('placeholders') ? json_encode($request->input('placeholders')) : null,
            'is_active'     => 1,
            'updated_by'    => $request->auth->id ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Template created successfully',
            'data'    => $template,
        ], 201);
    }

    // ── Delete template ───────────────────────────────────────────────────

    public function destroy($id)
    {
        $template = SystemEmailTemplate::find($id);
        if (!$template) {
            return response()->json(['success' => false, 'message' => 'Template not found'], 404);
        }

        \Illuminate\Support\Facades\Cache::forget('sys_email_tpl:' . $template->template_key);
        $template->delete();

        return response()->json([
            'success' => true,
            'message' => 'Template deleted successfully',
        ]);
    }

    // ── Update template ─────────────────────────────────────────────────────

    public function update(Request $request, $id)
    {
        $this->validate($request, [
            'subject'   => 'sometimes|string|max:255',
            'body_html' => 'sometimes|string',
            'is_active' => 'sometimes|boolean',
            'template_name' => 'sometimes|string|max:100',
        ]);

        try {
            $data = $request->only(['subject', 'body_html', 'is_active', 'template_name']);
            $data['updated_by'] = $request->auth->id ?? null;

            $template = $this->service->update($id, $data);

            return response()->json([
                'success' => true,
                'message' => 'Template updated successfully',
                'data'    => $template,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Template not found'], 404);
        }
    }

    // ── Preview rendered template ───────────────────────────────────────────

    public function preview(Request $request, $id)
    {
        $template = SystemEmailTemplate::find($id);
        if (!$template) {
            return response()->json(['success' => false, 'message' => 'Template not found'], 404);
        }

        $sampleData = $request->input('sample_data', []);
        $result = $this->service->preview($template->template_key, $sampleData);

        return response()->json([
            'success' => true,
            'data'    => $result,
        ]);
    }

    // ── Send test email ─────────────────────────────────────────────────────

    public function testSend(Request $request, $id)
    {
        $this->validate($request, [
            'to_email' => 'required|email',
        ]);

        $template = SystemEmailTemplate::find($id);
        if (!$template) {
            return response()->json(['success' => false, 'message' => 'Template not found'], 404);
        }

        try {
            $sampleData = $this->service->getSampleData($template->template_key);

            SystemMailerService::send(
                $template->template_key,
                $request->input('to_email'),
                $sampleData
            );

            return response()->json([
                'success' => true,
                'message' => 'Test email sent to ' . $request->input('to_email'),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send test email: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ── Seed default templates ──────────────────────────────────────────────

    public function seed()
    {
        $count = $this->service->seedDefaults();

        return response()->json([
            'success' => true,
            'message' => $count > 0
                ? "{$count} default template(s) seeded."
                : 'All default templates already exist.',
            'inserted' => $count,
        ]);
    }
}
