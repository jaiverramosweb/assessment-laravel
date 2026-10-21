<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Full application schema, same layout as the original app:
 * - UUID text ids, so counts are not exposed and records cannot be guessed.
 * - Dates as integer milliseconds UTC. The assessment timezone is only used for display
 *   and to interpret what the admin types.
 * - Stored session ids are the SHA-256 of the cookie token.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Current time in ms, computed with julianday so it works on older SQLite builds.
        $nowMs = DB::raw("(CAST((julianday('now') - 2440587.5) * 86400000 AS INTEGER))");

        Schema::create('admin_users', function (Blueprint $t) use ($nowMs) {
            $t->string('id')->primary();
            $t->string('email')->unique();
            $t->string('name');
            $t->string('password_hash');
            $t->bigInteger('last_login_at')->nullable();
            $t->bigInteger('created_at')->default($nowMs);
        });

        Schema::create('admin_sessions', function (Blueprint $t) use ($nowMs) {
            $t->string('id')->primary();
            $t->string('admin_id');
            $t->bigInteger('expires_at');
            $t->bigInteger('created_at')->default($nowMs);
            $t->foreign('admin_id')->references('id')->on('admin_users')->cascadeOnDelete();
            $t->index('admin_id', 'admin_sessions_admin_idx');
        });

        Schema::create('clients', function (Blueprint $t) use ($nowMs) {
            $t->string('id')->primary();
            $t->string('name');
            $t->binary('logo_data')->nullable();
            $t->string('logo_mime')->nullable();
            $t->bigInteger('logo_updated_at')->nullable();
            $t->string('theme_primary')->nullable();
            $t->string('theme_accent')->nullable();
            $t->string('created_by_id')->nullable();
            $t->bigInteger('created_at')->default($nowMs);
            $t->bigInteger('updated_at')->default($nowMs);
            $t->foreign('created_by_id')->references('id')->on('admin_users')->nullOnDelete();
        });

        Schema::create('assessments', function (Blueprint $t) use ($nowMs) {
            $t->string('id')->primary();
            $t->string('client_id')->nullable();
            $t->string('slug')->unique();
            $t->string('title');
            $t->text('description')->default('');
            $t->text('instructions')->default('');
            $t->bigInteger('starts_at');
            $t->bigInteger('ends_at');
            $t->string('timezone')->default('America/Bogota');
            $t->string('access_mode')->default('invitados');
            $t->string('access_password_hash')->nullable();
            $t->text('access_password_enc')->nullable();
            $t->integer('open_max_chars')->default(8000);
            $t->string('status')->default('borrador');
            $t->text('invite_subject')->default('');
            $t->text('invite_body')->default('');
            $t->string('duplicated_from_id')->nullable();
            $t->string('series_id')->nullable();
            $t->integer('series_order')->nullable();
            $t->string('created_by_id')->nullable();
            $t->bigInteger('created_at')->default($nowMs);
            $t->bigInteger('updated_at')->default($nowMs);
            $t->foreign('client_id')->references('id')->on('clients')->restrictOnDelete();
            $t->foreign('created_by_id')->references('id')->on('admin_users')->nullOnDelete();
        });

        Schema::create('questions', function (Blueprint $t) use ($nowMs) {
            $t->string('id')->primary();
            $t->string('assessment_id');
            $t->string('type');
            $t->text('prompt');
            $t->integer('position');
            $t->boolean('required')->default(true);
            $t->bigInteger('created_at')->default($nowMs);
            $t->foreign('assessment_id')->references('id')->on('assessments')->cascadeOnDelete();
            $t->index(['assessment_id', 'position'], 'questions_assessment_idx');
        });

        Schema::create('question_options', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('question_id');
            $t->text('text');
            $t->integer('position');
            // Expected option. Never sent to the participant's browser.
            $t->boolean('is_correct')->default(false);
            $t->foreign('question_id')->references('id')->on('questions')->cascadeOnDelete();
            $t->index(['question_id', 'position'], 'question_options_question_idx');
        });

        Schema::create('participants', function (Blueprint $t) use ($nowMs) {
            $t->string('id')->primary();
            $t->string('assessment_id');
            $t->string('name');
            // Always lowercase and trimmed.
            $t->string('email');
            $t->string('area')->nullable();
            $t->string('cargo')->nullable();
            $t->text('extra')->nullable();
            $t->string('source')->default('cargado');
            $t->bigInteger('invited_at')->nullable();
            $t->bigInteger('created_at')->default($nowMs);
            $t->foreign('assessment_id')->references('id')->on('assessments')->cascadeOnDelete();
            $t->unique(['assessment_id', 'email'], 'participants_assessment_email_uq');
        });

        Schema::create('participant_sessions', function (Blueprint $t) use ($nowMs) {
            $t->string('id')->primary();
            $t->string('participant_id');
            $t->bigInteger('expires_at');
            $t->bigInteger('created_at')->default($nowMs);
            $t->foreign('participant_id')->references('id')->on('participants')->cascadeOnDelete();
            $t->index('participant_id', 'participant_sessions_participant_idx');
        });

        // One row per participant (unique index), so the database guarantees a single submission.
        // submitted_at null = in progress (draft); set = delivered.
        Schema::create('submissions', function (Blueprint $t) use ($nowMs) {
            $t->string('id')->primary();
            $t->string('participant_id')->unique();
            $t->string('assessment_id');
            $t->bigInteger('started_at')->default($nowMs);
            $t->bigInteger('submitted_at')->nullable();
            $t->bigInteger('updated_at')->default($nowMs);
            $t->foreign('participant_id')->references('id')->on('participants')->cascadeOnDelete();
            $t->foreign('assessment_id')->references('id')->on('assessments')->cascadeOnDelete();
            $t->index('assessment_id', 'submissions_assessment_idx');
        });

        Schema::create('answers', function (Blueprint $t) use ($nowMs) {
            $t->string('id')->primary();
            $t->string('submission_id');
            $t->string('question_id');
            $t->string('option_id')->nullable();
            $t->text('text')->nullable();
            $t->bigInteger('updated_at')->default($nowMs);
            $t->foreign('submission_id')->references('id')->on('submissions')->cascadeOnDelete();
            $t->foreign('question_id')->references('id')->on('questions')->cascadeOnDelete();
            $t->foreign('option_id')->references('id')->on('question_options')->nullOnDelete();
            $t->unique(['submission_id', 'question_id'], 'answers_submission_question_uq');
            $t->index('question_id', 'answers_question_idx');
        });

        Schema::create('ai_analyses', function (Blueprint $t) use ($nowMs) {
            $t->string('id')->primary();
            $t->string('assessment_id');
            // Null for the global analysis (all open questions).
            $t->string('question_id')->nullable();
            $t->string('scope');
            $t->string('model');
            $t->text('result');
            // Hash of the analyzed answers: tells whether there are new answers.
            $t->string('input_hash');
            $t->integer('response_count');
            $t->string('created_by_id')->nullable();
            $t->bigInteger('created_at')->default($nowMs);
            $t->foreign('assessment_id')->references('id')->on('assessments')->cascadeOnDelete();
            $t->foreign('question_id')->references('id')->on('questions')->cascadeOnDelete();
            $t->foreign('created_by_id')->references('id')->on('admin_users')->nullOnDelete();
            $t->index(['assessment_id', 'question_id'], 'ai_analyses_assessment_idx');
        });

        // Failed attempt counters for rate limiting, e.g. "admin-login:ip:1.2.3.4".
        Schema::create('auth_attempts', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->integer('failures')->default(0);
            $t->bigInteger('window_start');
            $t->bigInteger('locked_until')->nullable();
        });

        Schema::create('audit_log', function (Blueprint $t) use ($nowMs) {
            $t->id();
            $t->string('admin_id')->nullable();
            $t->string('action');
            $t->string('entity_type')->nullable();
            $t->string('entity_id')->nullable();
            $t->text('metadata')->nullable();
            $t->string('ip')->nullable();
            $t->bigInteger('created_at')->default($nowMs);
            $t->foreign('admin_id')->references('id')->on('admin_users')->nullOnDelete();
            $t->index('created_at', 'audit_log_created_idx');
        });

        Schema::create('email_log', function (Blueprint $t) use ($nowMs) {
            $t->string('id')->primary();
            $t->string('participant_id');
            $t->string('status');
            $t->text('error')->nullable();
            $t->string('sent_by_id')->nullable();
            $t->bigInteger('created_at')->default($nowMs);
            $t->foreign('participant_id')->references('id')->on('participants')->cascadeOnDelete();
            $t->foreign('sent_by_id')->references('id')->on('admin_users')->nullOnDelete();
            $t->index('participant_id', 'email_log_participant_idx');
        });
    }

    public function down(): void
    {
        foreach ([
            'email_log', 'audit_log', 'auth_attempts', 'ai_analyses', 'answers', 'submissions',
            'participant_sessions', 'participants', 'question_options', 'questions', 'assessments',
            'clients', 'admin_sessions', 'admin_users',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
