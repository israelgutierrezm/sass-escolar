<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Academico\Asignatura;
use App\Models\Academico\Campus;
use App\Models\Academico\Carrera;
use App\Models\Academico\Oferta;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\Historial;
use App\Models\Identidad\Persona;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Datos de institución realistas para el tenant demo, para pruebas de volumen.
 *
 * Borra el dominio académico/escolar (campus, carreras, planes, materias,
 * ofertas, ciclos, grupos, docentes, alumnos y sus kárdex) SIN tocar el login,
 * los roles ni los catálogos, y siembra:
 *  - 2 campus.
 *  - 10 carreras de varios niveles (licenciaturas y posgrados).
 *  - 2 planes por carrera: uno antiguo (no vigente) y uno actual (vigente).
 *  - malla completa por plan (≈48 materias en lic, 18-28 en posgrado).
 *  - 15 alumnos, cada uno con 2-3 matrículas (una concluida, otra en curso),
 *    con su kárdex generado.
 */
class PoblarInstitucionDemoSeeder extends Seeder
{
    private const NIVEL_LIC = 81;
    private const NIVEL_MAESTRIA = 82;
    private const NIVEL_ESPECIALIDAD = 85;
    private const NIVEL_DOCTORADO = 95;
    private const PERIODO_SEMESTRE = 91;
    private const PERIODO_CUATRIMESTRE = 93;

    private int $matriculaSeq = 1;

    /** @var array<int, Ciclo> */
    private array $ciclos = [];

    /** @var array<string, string[]> */
    private array $temas = [
        'derecho' => ['Introducción al Estudio del Derecho', 'Derecho Romano', 'Teoría General del Proceso', 'Derecho Constitucional', 'Derecho Civil', 'Derecho Penal', 'Derecho Mercantil', 'Derecho Laboral', 'Derecho Fiscal', 'Derecho Administrativo', 'Derecho Internacional Público', 'Juicio de Amparo', 'Criminología', 'Derechos Humanos', 'Filosofía del Derecho', 'Derecho Procesal Civil', 'Derecho Procesal Penal', 'Contratos Civiles'],
        'admin' => ['Administración General', 'Contabilidad Financiera', 'Microeconomía', 'Macroeconomía', 'Matemáticas Financieras', 'Comportamiento Organizacional', 'Mercadotecnia', 'Recursos Humanos', 'Finanzas Corporativas', 'Administración de la Producción', 'Planeación Estratégica', 'Derecho Empresarial', 'Costos', 'Investigación de Operaciones', 'Comercio Internacional', 'Emprendimiento', 'Gestión de la Calidad', 'Logística'],
        'conta' => ['Contabilidad Básica', 'Contabilidad Intermedia', 'Contabilidad de Costos', 'Contabilidad Fiscal', 'Auditoría', 'Impuestos', 'Contabilidad Gubernamental', 'Finanzas', 'Nóminas y Seguridad Social', 'Normas de Información Financiera', 'Contabilidad Administrativa', 'Presupuestos', 'Dictamen Fiscal', 'Contabilidad Internacional', 'Análisis Financiero', 'Derecho Mercantil', 'Economía', 'Estadística Aplicada'],
        'psico' => ['Introducción a la Psicología', 'Bases Biológicas de la Conducta', 'Psicología del Desarrollo', 'Psicología Social', 'Psicología del Aprendizaje', 'Psicopatología', 'Psicometría', 'Psicología Clínica', 'Terapia Cognitivo-Conductual', 'Psicología Educativa', 'Psicología Organizacional', 'Neuropsicología', 'Evaluación Psicológica', 'Psicología Experimental', 'Ética Profesional', 'Entrevista Psicológica', 'Psicología de la Personalidad', 'Intervención en Crisis'],
        'sistemas' => ['Fundamentos de Programación', 'Matemáticas Discretas', 'Cálculo Diferencial', 'Estructura de Datos', 'Programación Orientada a Objetos', 'Bases de Datos', 'Sistemas Operativos', 'Redes de Computadoras', 'Ingeniería de Software', 'Arquitectura de Computadoras', 'Desarrollo Web', 'Inteligencia Artificial', 'Seguridad Informática', 'Cómputo en la Nube', 'Programación Móvil', 'Análisis de Algoritmos', 'Minería de Datos', 'Interacción Humano-Computadora'],
        'mkt' => ['Fundamentos de Mercadotecnia', 'Comportamiento del Consumidor', 'Investigación de Mercados', 'Publicidad', 'Marketing Digital', 'Branding', 'Comunicación Integral', 'Mercadotecnia de Servicios', 'Plan de Mercadotecnia', 'Ventas', 'Relaciones Públicas', 'Comercio Electrónico', 'Diseño de Producto', 'Estrategia de Precios', 'Analítica Digital', 'Neuromarketing', 'Mercadotecnia Internacional', 'Gestión de Marca'],
    ];

    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $this->limpiar();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $campus = $this->crearCampus();
        $this->crearCiclos();
        $carreras = $this->crearCarrerasYPlanes();
        $this->crearOfertas($carreras, $campus);
        $this->crearAlumnos($carreras);
        $this->crearTutores();
        $this->crearAspirantes();
        $this->crearDocentes($campus);
        $this->crearStaffPorCampus($campus);

        $this->command?->info('Institución demo poblada.');
    }

    private function limpiar(): void
    {
        // IDs de personas que son alumnos, docentes o tutores: se purgan con
        // sus cuentas (el tutor es una persona más, ligada por tutores_alumno).
        $personasPurgar = DB::table('alumnos')->pluck('persona_id')
            ->merge(DB::table('docentes')->pluck('persona_id'))
            ->merge(DB::table('tutores_alumno')->pluck('tutor_persona_id'))
            ->merge(DB::table('aspirantes')->pluck('persona_id'))
            ->unique()->filter()->all();

        $tablas = [
            'calificaciones_componente', 'historial', 'inscripcion', 'equivalencias',
            'contadores_acta', 'actas',
            'horarios_asignatura_grupo', 'docente_asignatura_grupo', 'tutor_asignatura_grupo',
            'asignatura_grupo', 'grupos',
            'certificaciones', 'lotes_certificacion',
            'tutores_alumno',
            'respuestas_campo', 'expedientes', 'matricula_oferta', 'alumnos',
            'documentos_docente', 'titulos_docente', 'campus_docente', 'docentes',
            'aspirantes',
            'oferta', 'seriacion', 'esquema_evaluacion', 'plan_materias', 'planes_estudio',
            'asignaturas', 'carreras',
            'ciclo_nivel', 'ciclo_campus', 'campus_ciclo', 'ciclos',
            'campus',
        ];

        foreach ($tablas as $t) {
            if (DB::getSchemaBuilder()->hasTable($t)) {
                DB::table($t)->delete();
            }
        }

        if ($personasPurgar !== []) {
            DB::table('persona_rol')->whereIn('persona_id', $personasPurgar)->delete();
            DB::table('usuarios')->whereIn('persona_id', $personasPurgar)->delete();
            DB::table('personas')->whereIn('id', $personasPurgar)->delete();
        }
    }

    /** @return Campus[] */
    private function crearCampus(): array
    {
        return [
            Campus::create(['clave' => 'CENTRO', 'nombre' => 'Campus Centro']),
            Campus::create(['clave' => 'NORTE', 'nombre' => 'Campus Norte']),
        ];
    }

    private function crearCiclos(): void
    {
        // Semestrales de 2016 a 2026: los pasados cerrados, el último abierto.
        $hoy = now();
        foreach (range(2016, 2026) as $anio) {
            foreach ([1, 2] as $np) {
                $inicio = $np === 1 ? "{$anio}-01-15" : "{$anio}-08-01";
                $fin = $np === 1 ? "{$anio}-06-30" : "{$anio}-12-15";
                $abierto = $hoy->between(\Illuminate\Support\Carbon::parse($inicio)->subDays(30), \Illuminate\Support\Carbon::parse($fin));
                $this->ciclos[] = Ciclo::create([
                    'clave' => "{$anio}-{$np}",
                    'anio' => $anio,
                    'numero_periodo' => $np,
                    'nombre' => ($np === 1 ? 'Enero-Junio ' : 'Agosto-Diciembre ').$anio,
                    'fecha_inicio' => $inicio,
                    'fecha_fin' => $fin,
                    'situacion_id' => $abierto ? 2 : (\Illuminate\Support\Carbon::parse($fin)->isFuture() ? 1 : 4),
                ]);
            }
        }
    }

    /** Índice del ciclo por clave "AAAA-N". */
    private function cicloId(int $anio, int $np): int
    {
        foreach ($this->ciclos as $c) {
            if ($c->anio === $anio && $c->numero_periodo === $np) {
                return $c->id;
            }
        }

        return $this->ciclos[array_key_last($this->ciclos)]->id;
    }

    /**
     * @return array<int, array<string, mixed>> cada una con carrera + planes[] (con plan_materias[])
     */
    private function crearCarrerasYPlanes(): array
    {
        $defs = [
            ['Licenciatura en Derecho', 'DER', self::NIVEL_LIC, self::PERIODO_SEMESTRE, 9, 48, 'derecho'],
            ['Licenciatura en Administración de Empresas', 'ADM', self::NIVEL_LIC, self::PERIODO_SEMESTRE, 9, 48, 'admin'],
            ['Licenciatura en Contaduría Pública', 'CP', self::NIVEL_LIC, self::PERIODO_SEMESTRE, 9, 48, 'conta'],
            ['Licenciatura en Psicología', 'PSI', self::NIVEL_LIC, self::PERIODO_SEMESTRE, 9, 48, 'psico'],
            ['Ingeniería en Sistemas Computacionales', 'ISC', self::NIVEL_LIC, self::PERIODO_SEMESTRE, 9, 48, 'sistemas'],
            ['Licenciatura en Mercadotecnia', 'MKT', self::NIVEL_LIC, self::PERIODO_SEMESTRE, 9, 48, 'mkt'],
            ['Maestría en Administración', 'MADM', self::NIVEL_MAESTRIA, self::PERIODO_CUATRIMESTRE, 4, 24, 'admin'],
            ['Maestría en Derecho Corporativo', 'MDC', self::NIVEL_MAESTRIA, self::PERIODO_CUATRIMESTRE, 4, 24, 'derecho'],
            ['Especialidad en Finanzas', 'EFIN', self::NIVEL_ESPECIALIDAD, self::PERIODO_CUATRIMESTRE, 3, 18, 'conta'],
            ['Doctorado en Ciencias Administrativas', 'DCA', self::NIVEL_DOCTORADO, self::PERIODO_CUATRIMESTRE, 6, 24, 'admin'],
        ];

        $carreras = [];

        foreach ($defs as [$nombre, $clave, $nivel, $tipoPeriodo, $periodos, $totalMaterias, $tema]) {
            $carrera = Carrera::create([
                'identificador' => (string) Str::uuid(),
                'clave' => $clave,
                'nombre' => $nombre,
                'nivel_estudios_id' => $nivel,
            ]);

            $planes = [];
            foreach ([['2016', false], ['2022', true]] as [$anioPlan, $vigente]) {
                $plan = PlanEstudio::create([
                    'carrera_id' => $carrera->id,
                    'clave' => "{$clave}-{$anioPlan}",
                    'abreviacion' => "{$clave}{$anioPlan}",
                    'nombre' => "Plan {$anioPlan}",
                    'rvoe' => "RVOE-{$clave}-{$anioPlan}",
                    'fecha_rvoe' => "{$anioPlan}-05-01",
                    'autorizacion_reconocimiento_id' => 1,
                    'tipo_periodo_id' => $tipoPeriodo,
                    'total_periodos' => $periodos,
                    'calificacion_minima' => 0,
                    'calificacion_maxima' => 10,
                    'calificacion_minima_aprobatoria' => $nivel === self::NIVEL_LIC ? 6 : 8,
                    'minimo_asignaturas' => $totalMaterias,
                    'minimo_creditos' => $totalMaterias * 7,
                    'total_creditos' => $totalMaterias * 7,
                    'vigente' => $vigente,
                ]);

                $planMaterias = $this->crearMalla($plan, $tema, $periodos, $totalMaterias, $clave.$anioPlan);
                $planes[] = ['plan' => $plan, 'materias' => $planMaterias, 'periodos' => $periodos, 'vigente' => $vigente];
            }

            $carreras[] = ['carrera' => $carrera, 'nivel' => $nivel, 'planes' => $planes];
        }

        return $carreras;
    }

    /**
     * Crea las asignaturas y la malla (plan_materias) de un plan, repartiendo N
     * materias en P periodos.
     *
     * @return PlanMateria[]
     */
    private function crearMalla(PlanEstudio $plan, string $tema, int $periodos, int $total, string $prefijo): array
    {
        $nombres = $this->nombresMaterias($tema, $total);
        $base = intdiv($total, $periodos);
        $resto = $total % $periodos;

        $planMaterias = [];
        $i = 0;
        for ($periodo = 1; $periodo <= $periodos; $periodo++) {
            $enEste = $base + ($periodo <= $resto ? 1 : 0);
            for ($k = 0; $k < $enEste && $i < $total; $k++, $i++) {
                $creditos = random_int(5, 8);
                $teoria = random_int(2, 4);
                $asignatura = Asignatura::create([
                    'identificador' => (string) Str::uuid(),
                    'clave' => sprintf('%s-%03d', $prefijo, $i + 1),
                    'nombre' => $nombres[$i],
                    'creditos' => $creditos,
                    'tipo_asignatura_id' => 263,
                    'clasificacion_id' => 3,
                    'area_id' => $periodo <= 3 ? 1 : ($periodo <= 6 ? 2 : 3),
                    'horas_teoria' => $teoria,
                    'horas_practica' => $creditos - $teoria > 0 ? $creditos - $teoria : 1,
                ]);

                $planMaterias[] = PlanMateria::create([
                    'plan_id' => $plan->id,
                    'asignatura_id' => $asignatura->id,
                    'clave_en_plan' => sprintf('%s%02d%02d', substr($prefijo, 0, 3), $periodo, $k + 1),
                    'periodo' => $periodo,
                    'tipo' => 'obligatoria',
                ]);
            }
        }

        return $planMaterias;
    }

    /** @return string[] N nombres de materias plausibles del tema. */
    private function nombresMaterias(string $tema, int $n): array
    {
        $pool = $this->temas[$tema] ?? $this->temas['admin'];
        $genericas = ['Metodología de la Investigación', 'Estadística', 'Ética Profesional', 'Comunicación Oral y Escrita', 'Inglés', 'Desarrollo Humano', 'Contabilidad', 'Economía', 'Informática Aplicada', 'Seminario de Titulación'];

        $nombres = [];
        $ronda = 1;
        while (count($nombres) < $n) {
            foreach ($pool as $t) {
                $nombres[] = $ronda === 1 ? $t : "{$t} ".$this->romano($ronda);
                if (count($nombres) >= $n) {
                    break 2;
                }
            }
            foreach ($genericas as $g) {
                $nombres[] = "{$g} ".$this->romano($ronda);
                if (count($nombres) >= $n) {
                    break 2;
                }
            }
            $ronda++;
        }

        return $nombres;
    }

    private function sinAcentos(string $s): string
    {
        return strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U',
            'ñ' => 'n', 'Ñ' => 'N',
        ]);
    }

    private function romano(int $n): string
    {
        return ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII'][$n - 1] ?? (string) $n;
    }

    /**
     * @param  array<int, array<string, mixed>>  $carreras
     * @param  Campus[]  $campus
     */
    private function crearOfertas(array $carreras, array $campus): void
    {
        foreach ($carreras as $c) {
            foreach ($c['planes'] as $p) {
                // El plan actual se oferta en ambos campus; el antiguo solo en el
                // primero y cerrado (ya no recibe inscripción, pero sostiene el
                // historial de quienes lo cursaron).
                $campusDelPlan = $p['vigente'] ? $campus : [$campus[0]];
                foreach ($campusDelPlan as $ca) {
                    Oferta::create([
                        'carrera_id' => $c['carrera']->id,
                        'plan_id' => $p['plan']->id,
                        'campus_id' => $ca->id,
                        'modalidad' => 'presencial',
                        'estatus' => $p['vigente'] ? 'abierta' : 'cerrada',
                    ]);
                }
            }
        }
    }

    /**
     * Docentes de ejemplo, repartidos por campus (algunos en uno, otros en los
     * dos). Cada uno con CURP/RFC/correo, tipo, situación activa y clave.
     *
     * @param  Campus[]  $campus
     */
    private function crearDocentes(array $campus): void
    {
        $centro = $campus[0]->id;
        $norte = ($campus[1] ?? $campus[0])->id;
        $rolDocente = DB::table('roles')->where('name', 'docente')->value('id');

        // [nombre, ap1, ap2, sexo, tipo_docente_id, campusIds]
        $defs = [
            ['Roberto', 'Guzmán', 'Herrera', 'H', 1, [$centro]],
            ['Adriana', 'Salas', 'Vega', 'M', 1, [$centro]],
            ['Héctor', 'Navarro', 'Ríos', 'H', 2, [$centro]],
            ['Patricia', 'Fuentes', 'Mora', 'M', 1, [$norte]],
            ['Jorge', 'Cabrera', 'Luna', 'H', 2, [$norte]],
            ['Gabriela', 'Ibarra', 'Solís', 'M', 1, [$norte]],
            ['Arturo', 'Peña', 'Castro', 'H', 1, [$centro, $norte]],
            ['Verónica', 'Rangel', 'Ponce', 'M', 1, [$centro, $norte]],
            ['Guillermo', 'Tapia', 'Franco', 'H', 3, [$centro, $norte]],
        ];

        foreach ($defs as $i => [$nom, $ap1, $ap2, $sexo, $tipo, $campusIds]) {
            $email = 'docente.demo.'.($i + 1).'@escuela.mx';

            if (Persona::query()->where('email', $email)->exists()) {
                continue;
            }

            // Adultos de 35-54 años; CURP/RFC sin acentos y coherentes con el sexo.
            $dob = now()->subYears(35 + ($i % 20))->subDays($i * 11);
            $yy = $dob->format('ymd');
            $a1 = $this->sinAcentos($ap1);
            $a2 = $this->sinAcentos($ap2);
            $no = $this->sinAcentos($nom);
            $l4 = mb_strtoupper(mb_substr($a1, 0, 2).mb_substr($a2, 0, 1).mb_substr($no, 0, 1));
            $cons = mb_strtoupper(mb_substr($a1, 2, 1).mb_substr($a2, 2, 1).mb_substr($no, 2, 1));

            $persona = Persona::create([
                'nombre' => $nom,
                'primer_apellido' => $ap1,
                'segundo_apellido' => $ap2,
                'curp' => $l4.$yy.$sexo.'DF'.$cons.'09',
                'rfc' => $l4.$yy,
                'fecha_nacimiento' => $dob->toDateString(),
                'email' => $email,
                'correo_institucional' => mb_strtolower($no.'.'.$a1).'@docentes.escuela.mx',
                'celular' => '55'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            ]);

            $docente = Docente::create([
                'persona_id' => $persona->id,
                'clave_profesor' => sprintf('PROF-%03d', $i + 1),
                'cedula_profesional' => (string) random_int(1000000, 9999999),
                'tipo_docente_id' => $tipo,
                'situacion_id' => 1, // Activo
                'edicion_contenido' => 1,
            ]);

            $docente->campus()->sync($campusIds);

            // Cuenta de acceso con rol docente (para «Ver como» y su portal).
            $this->crearCuenta($persona, $rolDocente, $campusIds[0], 'docente.demo.'.($i + 1));
        }
    }

    /**
     * Dos cuentas de personal «administrativo» acotadas cada una a un campus,
     * para probar el alcance por campus del listado (staff.centro / staff.norte,
     * contraseña «password»). Idempotente por correo.
     *
     * @param  Campus[]  $campus
     */
    /**
     * Provisiona la cuenta de acceso de una persona con un rol dado. Así los
     * alumnos y docentes de la demo SON usuarios y se puede «Ver como» ellos
     * (alineado con la decisión de que todos los roles entren al sistema).
     * Idempotente: si ya tiene cuenta, no hace nada.
     */
    private function crearCuenta(Persona $persona, ?int $rolId, ?int $campusId, string $usuario): void
    {
        if ($rolId === null || Usuario::where('persona_id', $persona->id)->exists()) {
            return;
        }

        PersonaRol::firstOrCreate(
            ['persona_id' => $persona->id, 'rol_id' => $rolId, 'campus_id' => $campusId],
            ['activo' => true],
        );

        Usuario::create([
            'persona_id' => $persona->id,
            'usuario' => $usuario,
            'email' => $persona->email,
            'password' => Hash::make('password'),
            'acceso_configurado' => true,
            'rol_activo_id' => $rolId,
        ]);
    }

    /**
     * Unos padres/tutores de ejemplo, cada uno con cuenta (rol padre de familia)
     * y vinculado a uno o más alumnos. Así el directorio de padres no sale vacío
     * y se puede «Ver como» ellos. Idempotente por correo.
     */
    private function crearTutores(): void
    {
        $rolPadre = DB::table('roles')->where('name', 'padre_familia')->value('id');
        $alumnoPersonaIds = DB::table('alumnos')->pluck('persona_id')->values();

        if ($alumnoPersonaIds->isEmpty()) {
            return;
        }

        // [nombre, ap1, ap2, parentesco, cuántos alumnos vincular]
        $defs = [
            ['Jorge', 'Ramírez', 'Soto', 'padre', 2],
            ['Laura', 'Domínguez', 'Vega', 'madre', 1],
            ['Ernesto', 'Campos', 'Ruiz', 'tutor', 2],
        ];

        foreach ($defs as $i => [$nom, $ap1, $ap2, $parentesco, $cuantos]) {
            $email = 'tutor.demo.'.($i + 1).'@escuela.mx';

            if (Persona::query()->where('email', $email)->exists()) {
                continue;
            }

            $dob = now()->subYears(42 + $i)->subDays($i * 7);
            $yy = $dob->format('ymd');
            $sexo = $parentesco === 'madre' ? 'M' : 'H';
            $a1 = $this->sinAcentos($ap1);
            $a2 = $this->sinAcentos($ap2);
            $no = $this->sinAcentos($nom);
            $l4 = mb_strtoupper(mb_substr($a1, 0, 2).mb_substr($a2, 0, 1).mb_substr($no, 0, 1));
            $cons = mb_strtoupper(mb_substr($a1, 2, 1).mb_substr($a2, 2, 1).mb_substr($no, 2, 1));

            $persona = Persona::create([
                'nombre' => $nom,
                'primer_apellido' => $ap1,
                'segundo_apellido' => $ap2,
                'curp' => $l4.$yy.$sexo.'DF'.$cons.'09',
                'rfc' => $l4.$yy,
                'fecha_nacimiento' => $dob->toDateString(),
                'email' => $email,
                'celular' => '55'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            ]);

            $this->crearCuenta($persona, $rolPadre, null, 'tutor.demo.'.($i + 1));

            foreach ($alumnoPersonaIds->slice($i, $cuantos) as $alumnoPersonaId) {
                TutorAlumno::create([
                    'tutor_persona_id' => $persona->id,
                    'alumno_persona_id' => $alumnoPersonaId,
                    'parentesco' => $parentesco,
                    'puede_ver_academico' => true,
                    'puede_ver_finanzas' => $i === 0,
                ]);
            }
        }
    }

    /**
     * Aspirantes de ejemplo en distintas etapas del embudo (del prospecto recién
     * llegado al aceptado), cada uno con cuenta (rol aspirante) para poder «Ver
     * como» ellos. Su oferta de interés y campus salen de las ofertas ya creadas.
     * Idempotente por correo.
     */
    private function crearAspirantes(): void
    {
        $rolAspirante = DB::table('roles')->where('name', 'aspirante')->value('id');
        $ofertas = Oferta::query()->orderBy('id')->take(6)->get(['id', 'campus_id']);

        if ($ofertas->isEmpty()) {
            return;
        }

        // [nombre, ap1, ap2, sexo, situacion_id, etapa_crm_id, origen, aceptó, info_completa, validado]
        $defs = [
            ['Daniela', 'Estrada', 'Marín', 'M', 1, 1, 'web', false, false, false],
            ['Iker', 'Zamora', 'León', 'H', 2, 3, 'referido', true, true, false],
            ['Paola', 'Cortés', 'Nava', 'M', 2, 4, 'redes', true, true, true],
            ['Bruno', 'Aguilar', 'Rosas', 'H', 3, 5, 'campaña', true, true, true],
            ['Melissa', 'Vázquez', 'Peña', 'M', 1, 2, 'web', true, false, false],
        ];

        foreach ($defs as $i => [$nom, $ap1, $ap2, $sexo, $sit, $etapa, $origen, $terminos, $info, $validado]) {
            $email = 'aspirante.demo.'.($i + 1).'@correo.mx';

            if (Persona::query()->where('email', $email)->exists()) {
                continue;
            }

            // Jóvenes de ~18 años (prospectos a licenciatura).
            $dob = now()->subYears(18)->subDays($i * 40);
            $yy = $dob->format('ymd');
            $a1 = $this->sinAcentos($ap1);
            $a2 = $this->sinAcentos($ap2);
            $no = $this->sinAcentos($nom);
            $l4 = mb_strtoupper(mb_substr($a1, 0, 2).mb_substr($a2, 0, 1).mb_substr($no, 0, 1));
            $cons = mb_strtoupper(mb_substr($a1, 2, 1).mb_substr($a2, 2, 1).mb_substr($no, 2, 1));

            $persona = Persona::create([
                'nombre' => $nom,
                'primer_apellido' => $ap1,
                'segundo_apellido' => $ap2,
                'curp' => $l4.$yy.$sexo.'DF'.$cons.'09',
                'rfc' => $l4.$yy,
                'fecha_nacimiento' => $dob->toDateString(),
                'email' => $email,
                'celular' => '55'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            ]);

            // La cuenta se crea ANTES del Aspirante: si un observer provisiona una
            // cuenta «censo», ésta ya existe y es la usable (con rol activo).
            $this->crearCuenta($persona, $rolAspirante, null, 'aspirante.demo.'.($i + 1));

            $oferta = $ofertas[$i % $ofertas->count()];

            Aspirante::create([
                'persona_id' => $persona->id,
                'oferta_interes_id' => $oferta->id,
                'campus_id' => $oferta->campus_id,
                'clave_aspirante' => sprintf('ASP-2026-%03d', $i + 1),
                'situacion_id' => $sit,
                'etapa_crm_id' => $etapa,
                'paso' => $info ? 3 : 1,
                'acepto_terminos' => $terminos,
                'info_personal_completa' => $info,
                'validado_admin' => $validado,
                'origen' => $origen,
            ]);
        }
    }

    private function crearStaffPorCampus(array $campus): void
    {
        $rolId = DB::table('roles')->where('name', 'administrativo')->value('id');

        if ($rolId === null) {
            return;
        }

        foreach ([[$campus[0], 'staff.centro'], [$campus[1] ?? $campus[0], 'staff.norte']] as [$ca, $usuario]) {
            $email = $usuario.'@escuela.mx';

            if (Usuario::where('email', $email)->exists()) {
                continue;
            }

            $persona = Persona::create([
                'nombre' => 'Staff',
                'primer_apellido' => $ca->nombre,
                'email' => $email,
            ]);

            PersonaRol::create([
                'persona_id' => $persona->id,
                'rol_id' => $rolId,
                'campus_id' => $ca->id,
                'activo' => true,
            ]);

            Usuario::create([
                'persona_id' => $persona->id,
                'usuario' => $usuario,
                'email' => $email,
                'password' => Hash::make('password'),
                'acceso_configurado' => true,
                'rol_activo_id' => $rolId,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $carreras
     */
    private function crearAlumnos(array $carreras): void
    {
        $lics = array_values(array_filter($carreras, fn ($c) => $c['nivel'] === self::NIVEL_LIC));
        $posgrados = array_values(array_filter($carreras, fn ($c) => $c['nivel'] !== self::NIVEL_LIC));

        $activo = SituacionAlumno::where('clave', 'activo')->value('id');
        $egresado = SituacionAlumno::where('clave', 'egresado')->value('id');
        $rolAlumno = DB::table('roles')->where('name', 'alumno')->value('id');

        $campusIds = Campus::orderBy('id')->pluck('id')->all();
        $centro = $campusIds[0];
        $norte = $campusIds[1] ?? $campusIds[0];

        $nombres = ['Sofía', 'Mateo', 'Valentina', 'Santiago', 'Regina', 'Emiliano', 'Ximena', 'Diego', 'Fernanda', 'Sebastián', 'Camila', 'Leonardo', 'Renata', 'Alejandro', 'Andrea'];
        $apellidos = ['García', 'Martínez', 'López', 'Hernández', 'González', 'Pérez', 'Ramírez', 'Torres', 'Flores', 'Rivera', 'Gómez', 'Díaz', 'Cruz', 'Morales', 'Reyes', 'Ortiz'];

        // Sexo alineado a $nombres (índice par = mujer): la CURP lo respeta.
        for ($i = 0; $i < 15; $i++) {
            $nom = $nombres[$i];
            $ap1 = $apellidos[$i % count($apellidos)];
            $ap2 = $apellidos[($i + 5) % count($apellidos)];
            $sexo = $i % 2 === 0 ? 'M' : 'H';

            // Cumpleaños escalonados: el alumno 0 cumple HOY, el resto en 5, 10…
            // días, para lucir la cuenta regresiva del encabezado. Edad ~20-25.
            $dob = now()->addDays($i * 5)->subYears(20 + ($i % 6));
            $yy = $dob->format('ymd');

            // CURP/RFC con formato plausible, sin acentos y coherentes con el
            // sexo (es dato de prueba: no llevan dígito verificador real).
            $a1 = $this->sinAcentos($ap1);
            $a2 = $this->sinAcentos($ap2);
            $no = $this->sinAcentos($nom);
            $l4 = mb_strtoupper(mb_substr($a1, 0, 2).mb_substr($a2, 0, 1).mb_substr($no, 0, 1));
            $cons = mb_strtoupper(mb_substr($a1, 2, 1).mb_substr($a2, 2, 1).mb_substr($no, 2, 1));

            $persona = Persona::create([
                'nombre' => $nom,
                'primer_apellido' => $ap1,
                'segundo_apellido' => $ap2,
                'curp' => $l4.$yy.$sexo.'DF'.$cons.'09',
                'rfc' => $l4.$yy,
                'fecha_nacimiento' => $dob->toDateString(),
                'email' => 'alumno.demo.'.($i + 1).'@escuela.mx',
                'correo_institucional' => mb_strtolower($no.'.'.$a1).'@alumnos.escuela.mx',
                'celular' => '55'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            ]);

            DB::table('alumnos')->insert([
                'persona_id' => $persona->id,
                'situacion_id' => $activo,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // Cuenta de acceso con rol alumno: para poder «Ver como» el alumno.
            $this->crearCuenta($persona, $rolAlumno, null, 'alumno.demo.'.($i + 1));

            $a = $lics[$i % count($lics)];
            $b = $lics[($i + 3) % count($lics)];

            if ($i < 3) {
                // DOS licenciaturas EN CURSO a la vez, en campus distintos (para
                // ver «2 carreras activas» y «Múltiples campus»).
                $this->matricular($persona, $a, antiguo: false, concluida: false, egresado: $egresado, activo: $activo, anioIngreso: 2022, campusId: $centro);
                $this->matricular($persona, $b, antiguo: false, concluida: false, egresado: $egresado, activo: $activo, anioIngreso: 2023, campusId: $norte);
            } elseif ($i < 8) {
                // Una licenciatura concluida (plan antiguo, Centro) y otra en
                // curso; la activa alterna de campus.
                $this->matricular($persona, $a, antiguo: true, concluida: true, egresado: $egresado, activo: $activo, anioIngreso: 2016 + ($i % 3), campusId: $centro);
                $this->matricular($persona, $b, antiguo: false, concluida: false, egresado: $egresado, activo: $activo, anioIngreso: 2023, campusId: ($i % 2 === 0 ? $centro : $norte));
            } elseif ($i < 13) {
                // Licenciatura concluida + maestría en curso.
                $m = $posgrados[$i % count($posgrados)];
                $this->matricular($persona, $a, antiguo: true, concluida: true, egresado: $egresado, activo: $activo, anioIngreso: 2016 + ($i % 3), campusId: $centro);
                $this->matricular($persona, $m, antiguo: false, concluida: false, egresado: $egresado, activo: $activo, anioIngreso: 2024, campusId: ($i % 2 === 0 ? $centro : $norte));
            } else {
                // Licenciatura + maestría concluidas + doctorado en curso.
                $m = $posgrados[0];
                $d = $posgrados[count($posgrados) - 1];
                $this->matricular($persona, $a, antiguo: true, concluida: true, egresado: $egresado, activo: $activo, anioIngreso: 2016, campusId: $centro);
                $this->matricular($persona, $m, antiguo: true, concluida: true, egresado: $egresado, activo: $activo, anioIngreso: 2021, campusId: $centro);
                $this->matricular($persona, $d, antiguo: false, concluida: false, egresado: $egresado, activo: $activo, anioIngreso: 2025, campusId: $norte);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $carrera
     */
    private function matricular(Persona $persona, array $carrera, bool $antiguo, bool $concluida, int $egresado, int $activo, int $anioIngreso, ?int $campusId = null): void
    {
        $p = $carrera['planes'][$antiguo ? 0 : 1];
        $oferta = Oferta::where('plan_id', $p['plan']->id)
            ->when($campusId !== null, fn ($q) => $q->where('campus_id', $campusId))
            ->first()
            ?? Oferta::where('plan_id', $p['plan']->id)->first();

        if ($oferta === null) {
            return;
        }

        $clavePref = $carrera['nivel'] === self::NIVEL_LIC ? 'L' : ($carrera['nivel'] === self::NIVEL_DOCTORADO ? 'D' : 'P');
        $matricula = sprintf('%s%d%04d', $clavePref, $anioIngreso, $this->matriculaSeq++);

        $m = MatriculaOferta::create([
            'persona_id' => $persona->id,
            'oferta_id' => $oferta->id,
            'matricula' => $matricula,
            'generacion' => (string) $anioIngreso,
            'fecha_ingreso' => "{$anioIngreso}-08-01",
            'situacion_id' => $concluida ? $egresado : $activo,
            'estatus' => $concluida ? 'egresado' : 'activo',
        ]);

        $periodos = $p['periodos'];
        $cursados = $concluida ? $periodos : max(1, (int) ceil($periodos * 0.5));

        $this->generarKardex($m, $p['materias'], $anioIngreso, $cursados, $concluida, $periodos);
    }

    /**
     * @param  PlanMateria[]  $planMaterias
     */
    private function generarKardex(MatriculaOferta $m, array $planMaterias, int $anioIngreso, int $cursados, bool $concluida, int $periodos): void
    {
        $filas = [];

        foreach ($planMaterias as $pm) {
            $periodo = (int) $pm->periodo;
            if ($periodo > $cursados) {
                continue;
            }

            // El periodo N cae en el ciclo (anioIngreso + floor((N-1)/2)) con
            // número 1 o 2 según la paridad. Cada dos periodos avanza un año.
            $anio = $anioIngreso + intdiv($periodo - 1, 2);
            $np = ($periodo % 2 === 1) ? 2 : 1; // ingresan en agosto (periodo 2)
            $cicloId = $this->cicloId($anio, $np);

            $enCurso = ! $concluida && $periodo === $cursados;

            $filas[] = [
                'matricula_oferta_id' => $m->id,
                'plan_materia_id' => $pm->id,
                'ciclo_id' => $cicloId,
                'asignatura_grupo_id' => null,
                'tipo_evaluacion_id' => 1,
                'estatus_id' => $enCurso ? 3 : 1,
                'calificacion' => $enCurso ? null : random_int(70, 98) / 10,
                'observacion_asignatura_id' => 100,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }

        foreach (array_chunk($filas, 200) as $chunk) {
            DB::table('historial')->insert($chunk);
        }
    }
}
