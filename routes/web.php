<?php

use App\Http\Controllers\AiControlController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\PublicChatController;
use App\Http\Controllers\SourceController;
use App\Models\Message;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('inbox'));
Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Chat web del cliente (canal WebChannel): acceso por token de conversación, sin cuenta
Route::get('/chat', [PublicChatController::class, 'show'])->name('chat');
Route::post('/chat/start', [PublicChatController::class, 'start'])->middleware('throttle:20,1')->name('chat.start');
Route::get('/chat/{token}/messages', [PublicChatController::class, 'messages'])->name('chat.messages');
Route::post('/chat/{token}/messages', [PublicChatController::class, 'send'])->middleware('throttle:30,1')->name('chat.send');
Route::post('/chat/{token}/images', [PublicChatController::class, 'image'])->middleware('throttle:10,1')->name('chat.image');
Route::get('/chat/{token}/media/{message}', [PublicChatController::class, 'media'])->name('chat.media');

Route::middleware('auth')->group(function () {
    Route::get('/inbox', [InboxController::class, 'index'])->middleware('can:conversations.view')->name('inbox');
    Route::get('/inbox/{conversation}', [InboxController::class, 'index'])->middleware('can:conversations.view')->name('inbox.show');
    Route::post('/inbox/{conversation}/reply', [InboxController::class, 'reply'])->middleware('can:conversations.reply')->name('inbox.reply');
    Route::post('/inbox/{conversation}/suggest', [InboxController::class, 'suggest'])->middleware('can:conversations.reply')->name('inbox.suggest');
    Route::post('/inbox/{conversation}/mode', [InboxController::class, 'mode'])->middleware('can:conversations.reply')->name('inbox.mode');
    Route::post('/inbox/{conversation}/approve/{message}', [InboxController::class, 'approve'])->middleware('can:engineering.review')->name('inbox.approve');
    Route::get('/media/messages/{message}', fn (Message $message) => PublicChatController::stream($message))->middleware('can:conversations.view')->name('media.message');
    Route::post('/runs/{run}/feedback', [InboxController::class, 'feedback'])->middleware('can:conversations.reply')->name('runs.feedback');

    Route::get('/products', [CatalogController::class, 'index'])->middleware('can:catalog.view')->name('products');
    Route::get('/products/{product}', [CatalogController::class, 'show'])->middleware('can:catalog.view')->name('products.show');
    Route::get('/search/debug', [CatalogController::class, 'debug'])->middleware('can:catalog.view')->name('search.debug');
    Route::get('/api/command', [CatalogController::class, 'command'])->middleware('can:catalog.view')->name('command');

    Route::get('/documents', [DocumentController::class, 'index'])->middleware('can:catalog.view')->name('documents');
    Route::post('/documents', [DocumentController::class, 'store'])->middleware('can:documents.upload')->name('documents.store');
    Route::post('/documents/sync', [DocumentController::class, 'sync'])->middleware(['can:documents.upload', 'throttle:6,1'])->name('documents.sync');
    Route::post('/documents/notes', [DocumentController::class, 'storeNote'])->middleware('can:documents.upload')->name('documents.notes');
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->middleware('can:catalog.view')->name('documents.show');
    Route::get('/documents/{document}/file', [DocumentController::class, 'file'])->middleware('can:catalog.view')->name('documents.file');
    Route::post('/documents/{document}/reprocess', [DocumentController::class, 'reprocess'])->middleware('can:documents.upload')->name('documents.reprocess');
    Route::get('/sources/records/{record}', [SourceController::class, 'record'])->middleware('can:catalog.view')->name('sources.record');

    Route::get('/knowledge', [KnowledgeController::class, 'index'])->middleware('can:catalog.view')->name('knowledge');
    Route::post('/knowledge/conflicts/{conflict}', [KnowledgeController::class, 'resolveConflict'])->middleware('can:technical_data.review')->name('knowledge.conflict');
    Route::post('/knowledge/specs/{spec}', [KnowledgeController::class, 'reviewSpec'])->middleware('can:technical_data.review')->name('knowledge.spec');
    Route::post('/knowledge/relationships/{relationship}', [KnowledgeController::class, 'reviewRelationship'])->middleware('can:compatibilities.manage')->name('knowledge.relationship');

    Route::get('/ai', [AiControlController::class, 'index'])->middleware('can:ai.evals.run')->name('ai');
    Route::post('/ai/playground', [AiControlController::class, 'playground'])->middleware('can:ai.evals.run')->name('ai.playground');
    Route::post('/ai/prompts/{definition}', [AiControlController::class, 'saveDraft'])->middleware('can:ai.prompts.manage')->name('ai.prompts.draft');
    Route::post('/ai/prompts/versions/{version}/publish', [AiControlController::class, 'publish'])->middleware('can:ai.prompts.manage')->name('ai.prompts.publish');
    Route::post('/ai/rules/{rule}', [AiControlController::class, 'updateRule'])->middleware('can:ai.rules.manage')->name('ai.rules.update');
    Route::post('/ai/evals', [AiControlController::class, 'runEval'])->middleware('can:ai.evals.run')->name('ai.evals.run');
});
