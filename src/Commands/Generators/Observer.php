<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Commands\Generators;

use BlitzPHP\Cli\Console\Command;
use BlitzPHP\Cli\Traits\GeneratorTrait;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\String\Text;

/**
 * Génère un observateur d'entité.
 */
class Observer extends Command
{
    use GeneratorTrait;

    protected string $group = 'Generateurs';

    protected string $name = 'make:observer';

    protected string $description = 'Génère un observateur d\'entité.';

    protected string $service = 'Service de génération de code';

    protected array $arguments = [
        'name' => "Le nom de la classe de l'observateur.",
    ];

    protected array $options = [
        '--observe'   => "Le nom de la classe de l'entité obsersée",
        '--namespace' => ["Définit l'espace de noms racine. Par défaut\u{a0}: \"APP_NAMESPACE\".", APP_NAMESPACE],
        '--force'     => 'Forcer à écraser le fichier existant.',
    ];

    /**
     * {@inheritDoc}
     */
    public function handle()
    {
        $this->component     = 'Observer';
        $this->directory     = 'Observers';
        $this->template      = 'observer.tpl.php';
        $this->templatePath  = __DIR__ . '/Views';
        $this->classNameLang = 'CLI.generator.className.observer';

        $params = $this->parameters() + ['suffix' => true];

        $this->generateClass($params);
    }

    /**
     * {@inheritDoc}
     */
    protected function prepare(string $class): string
    {
        if (null === $observe = $this->option('observe')) {
            $observe = str_replace($this->directory, 'Entities', $class);
            $observe = preg_replace('#' . $this->component . '$#', '', $observe);
        }

        [$namespace, $observe] = Helpers::namespaceSplit($observe);
        $observe               = Text::convertTo($observe, 'pascal');

        if ($namespace === '') {
            $namespace = $this->getNamespace() . '\\Entities';
        }

        $useStatement = implode('\\', [$namespace, $observe]);

        return $this->parseTemplate(
            $class,
            ['{useStatement}', '{observe}', '{instance}'],
            [$useStatement, $observe, '$' . Text::camel($observe)],
        );
    }
}
