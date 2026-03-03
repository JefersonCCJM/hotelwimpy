# Guía de IA — Hotel Wimpy

> **Para cualquier agente de IA** que trabaje en este proyecto (Claude, Copilot, Cursor, GPT, Gemini, etc.).
> Este archivo se llama `CLAUDE.md` porque Claude Code lo requiere con ese nombre para leerlo automáticamente,
> pero todas sus directivas son agnósticas al agente.

---

## Stack del Proyecto

- **Backend:** PHP 8.3+ / Laravel 12
- **Frontend reactivo:** Livewire 3.5
- **Interacciones JS:** Alpine.js
- **Estilos:** Tailwind CSS
- **PDF:** barryvdh/laravel-dompdf
- **Permisos:** spatie/laravel-permission
- **Autenticación:** Laravel Sanctum

---

## PASO 0 — Antes de ejecutar CUALQUIER tarea

Antes de escribir una sola línea de código, el agente DEBE:

1. **Leer los archivos relevantes** — nunca proponer cambios sin leer primero el archivo.
2. **Entender los patrones existentes** — buscar cómo se hacen cosas similares ya en el proyecto y replicar ese estilo.
3. **Identificar el tipo de tarea** — determinar qué capa del stack está involucrada (modelo, controlador, componente Livewire, vista, API, etc.).
4. **Seleccionar las skills aplicables** según el orden definido abajo.
5. **No sobre-ingeniería** — hacer solo lo que se pide, sin agregar features, helpers, comentarios o abstracciones no solicitadas.

---

## Orden obligatorio de Skills

Las skills se aplican en cascada: primero las de capa inferior, luego las de capa superior. Si la tarea toca varias capas, aplicar todas las que correspondan en este orden.

### 1. `php-best-practices` — SIEMPRE primero
Aplica en todo código PHP.
- PHP 8.3+ (tipos nativos, enums, readonly, fibers)
- PSR-12 para estilo de código
- SOLID principles
- Type safety estricto (`declare(strict_types=1)`)
- Sin magic numbers, sin lógica duplicada

### 2. `laravel-best-practices` — SIEMPRE segundo (si hay PHP)
Aplica en todo lo que sea Laravel.
- Eloquent sobre query builder cuando sea posible
- Form Requests para validación (nunca validar en el controlador directamente)
- Policies para autorización (usar spatie/permission cuando aplique)
- Service classes para lógica de negocio compleja
- Migrations descriptivas, nunca modificar migraciones ya ejecutadas
- Observers, Events y Listeners en lugar de lógica en controllers
- Convenciones de nombres de Laravel (snake_case tablas, camelCase métodos, PascalCase clases)

### 3. `livewire-development` — si la tarea involucra componentes Livewire
Aplica cuando se crean o modifican componentes `.php` en `app/Livewire/` o vistas en `resources/views/livewire/`.
- Livewire 4 patterns (aunque el proyecto usa Livewire 3, prepararse para el estilo moderno)
- `wire:model`, `wire:click`, `wire:loading` correctamente
- Computed properties con `#[Computed]`
- Lazy loading cuando la lista es grande
- Evitar `$this->dispatch` innecesario; preferir eventos de Livewire
- Separar lógica de negocio del componente (usar Services)

### 4. `alpine-js` — si la tarea involucra interacciones JS en la vista
Aplica cuando hay `x-data`, `x-show`, `x-bind`, `@click` u otros directivos Alpine en Blade.
- Alpine solo para UI state efímero (tooltips, modales, toggles)
- No duplicar estado que ya vive en Livewire
- Comunicar Alpine → Livewire con `$wire`
- Comunicar Livewire → Alpine con `$dispatch`

### 5. `frontend-tailwind-best-practices` — si la tarea involucra clases CSS
Aplica en todos los archivos Blade que tengan clases Tailwind.
- Utility-first: no crear CSS custom salvo que sea inevitable
- Responsive con breakpoints de Tailwind (`sm:`, `md:`, `lg:`)
- Dark mode con `dark:` si el proyecto lo soporta
- Extraer componentes Blade (`x-` components) cuando un bloque se repite 3+ veces
- No hardcodear colores fuera de `tailwind.config.js`

### 6. `api-resource-patterns` — si la tarea involucra respuestas de API
Aplica cuando se crean o modifican Resources de Laravel (`app/Http/Resources/`).
- Siempre usar `JsonResource` o `ResourceCollection`
- Atributos condicionales con `when()` y `whenLoaded()`
- No exponer IDs internos innecesariamente
- Versionar respuestas si hay cambios breaking

### 7. `api-design-principles` — si se diseña o modifica una ruta de API
Aplica al crear/modificar rutas en `routes/api.php`.
- RESTful: sustantivos en plural, verbos HTTP correctos
- Status codes semánticamente correctos (201 Created, 422 Unprocessable, etc.)
- Paginación en colecciones grandes
- Autenticación con Sanctum en todas las rutas protegidas

---

## Reglas adicionales del proyecto

### Estructura de archivos
```
app/
  Http/
    Controllers/     → Controladores delgados (thin controllers)
    Requests/        → Validación con Form Requests
    Resources/       → API Resources
  Livewire/          → Componentes Livewire
  Models/            → Modelos Eloquent
  Services/          → Lógica de negocio
  Policies/          → Autorización
resources/
  views/
    livewire/        → Vistas de componentes Livewire
    layouts/         → Layouts base
    components/      → Componentes Blade reutilizables (x-)
```

### Convenciones críticas
- **Español en UI:** todos los textos visibles al usuario van en español
- **Inglés en código:** nombres de variables, métodos, clases, tablas en inglés
- **Soft deletes:** usar `SoftDeletes` en modelos donde el negocio lo requiera
- **Timestamps:** siempre presentes salvo justificación
- **Seguridad:** nunca exponer datos sensibles en responses; usar `$hidden` en modelos

### Lo que el agente NO debe hacer
- No crear archivos nuevos sin que sean necesarios
- No agregar comentarios o docblocks donde la lógica es obvia
- No refactorizar código que no fue pedido modificar
- No agregar manejo de errores para escenarios imposibles
- No instalar dependencias sin confirmar con el usuario
- No hacer `git push` ni operaciones destructivas sin confirmación explícita

---

## Skill: simplify — Post-escritura obligatoria

Después de escribir o modificar código, el agente DEBE aplicar una pasada de simplificación
sobre el código recién tocado sin alterar su comportamiento.

### Reglas de simplificación

1. **Preservar funcionalidad** — nunca cambiar lo que hace el código, solo cómo lo hace.
   Todos los outputs, features y comportamientos originales deben permanecer intactos.

2. **Aplicar estándares del proyecto** — seguir las convenciones definidas en este archivo:
   - PHP 8.3+: tipos nativos, `declare(strict_types=1)`, enums, readonly
   - PSR-12 para estilo de código
   - Eloquent sobre query builder cuando sea posible
   - Nombres en inglés (código), español (textos de UI)
   - `wire:model`, computed properties y eventos Livewire correctamente usados

3. **Mejorar claridad** — simplificar estructura sin perder legibilidad:
   - Reducir anidamiento innecesario
   - Eliminar código redundante y abstracciones sin uso
   - Mejorar nombres de variables y métodos cuando no son descriptivos
   - Consolidar lógica relacionada
   - Eliminar comentarios que describen lo obvio
   - **Evitar ternarios anidados** — preferir `match`, `if/else` o early returns
   - Elegir claridad sobre brevedad — código explícito es mejor que compacto

4. **Mantener equilibrio** — no sobre-simplificar:
   - No reducir claridad ni mantenibilidad
   - No crear soluciones "listas" difíciles de entender
   - No mezclar demasiadas responsabilidades en un método
   - No eliminar abstracciones útiles que mejoran la organización
   - No priorizar "menos líneas" sobre legibilidad
   - No hacer el código más difícil de depurar o extender

5. **Alcance acotado** — refinar solo el código modificado en la sesión actual,
   salvo que se indique explícitamente revisar un alcance mayor.

### Proceso del agente

```
1. Identificar las secciones de código recién modificadas
2. Analizar oportunidades de mejora de elegancia y consistencia
3. Aplicar estándares del proyecto definidos en este archivo
4. Verificar que la funcionalidad permanece intacta
5. Confirmar que el código refinado es más simple y mantenible
6. Documentar solo cambios significativos que afecten la comprensión
```

El agente aplica esto de forma autónoma y proactiva, sin esperar una solicitud explícita.
El objetivo es que todo código cumpla los más altos estándares de elegancia y mantenibilidad.

---

## Flujo de trabajo estándar

```
1. Leer archivos relevantes (SIEMPRE)
2. Identificar qué skills aplican (ver orden arriba)
3. Verificar patrones existentes en el proyecto
4. Implementar el mínimo necesario
5. Aplicar simplify sobre el código recién escrito
6. No marcar como completado si hay errores
```
