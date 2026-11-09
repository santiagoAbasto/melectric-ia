<?php

namespace Database\Seeders;

use App\AI\PromptRegistry;
use App\AI\RuleEngine;
use App\Catalog\SpecCatalog;
use App\Enums\Role;
use App\Models\AiProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Perfiles de IA (spec §72): mismo orquestador, distinto alcance de herramientas, umbral y modelo. */
    public const PROFILES = [
        'general_assistant' => ['Asistente general', 'Atención inicial, saludo, derivación y consultas generales', 0.6],
        'technical_sales' => ['Ventas técnicas', 'Consultas comerciales con contenido técnico (sin precios)', 0.6],
        'product_finder' => ['Buscador de productos', 'Búsqueda por referencia y por características', 0.6],
        'protection_specialist' => ['Especialista en protecciones', 'Termomagnéticas, diferenciales, compactos y seccionadores', 0.65],
        'motor_control_specialist' => ['Especialista en maniobra de motores', 'Contactores, guardamotores, relés térmicos y arranques', 0.65],
        'automation_specialist' => ['Especialista en automatización', 'Easy E4, relés de control, mando y señalización', 0.6],
        'power_factor_specialist' => ['Especialista en compensación', 'Capacitores y controladores de factor de potencia (búsqueda, no cálculo)', 0.65],
        'vfd_specialist' => ['Especialista en variadores', 'Variadores PI150/PI500 (sólo evidencia documental)', 0.7],
        'post_sales_support' => ['Soporte posventa', 'Garantías y reclamos: deriva a una persona', 0.6],
        'document_assistant' => ['Asistente de documentación', 'Dónde aparece cada dato: documento, página y tabla', 0.55],
        'human_copilot' => ['Copiloto del operador', 'Borradores para que el operador edite y envíe', 0.5],
    ];

    public const TOOLS = ['findReference', 'searchProducts', 'getProductSpecs', 'findCompatibleAccessories', 'compareProducts',
        'getProductDocuments', 'getTechnicalTable', 'searchKnowledge', 'requestHumanReview'];

    public function run(): void
    {
        SpecCatalog::ensure();
        RuleEngine::seed();
        PromptRegistry::seed();
        foreach (self::PROFILES as $key => [$name, $purpose, $min]) {
            AiProfile::query()->firstOrCreate(['key' => $key], [
                'name' => $name, 'purpose' => $purpose, 'provider' => config('melectric.ai.provider'), 'model' => 'router',
                'prompt_key' => 'answer_writer', 'tools' => self::TOOLS, 'min_confidence' => $min, 'max_tokens' => 900,
            ]);
        }
        // Usuarios de desarrollo: contraseña en DEV_USERS_PASSWORD (.env), nunca en el código.
        if (app()->environment('local') && ($password = env('DEV_USERS_PASSWORD'))) {
            foreach ([['Admin MElectric', 'admin@melectric.test', Role::ADMIN], ['Ingeniería', 'ingenieria@melectric.test', Role::TECHNICAL_ENGINEER],
                ['Ventas', 'ventas@melectric.test', Role::SALES_AGENT], ['Conocimiento', 'conocimiento@melectric.test', Role::KNOWLEDGE_MANAGER]] as [$name, $email, $role]) {
                User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => $password, 'role' => $role, 'is_active' => true]);
            }
        }
    }
}
