<?php

namespace Platformsh\Cli\Command\App\Platform;

use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;

class Php implements PlatformInterface {

    public function name()
    {
        return 'php';
    }

    public function versions()
    {
        return ['5.6', '7.0'];
    }

    public function getFields()
    {
        $fields['php_version'] = new OptionsField('Version', [
            'conditions' => ['type' => 'php'],
            'optionName' => 'php_version',
            'options' => ['5.6', '7.0'],
            'default' => '7.0',
        ]);

        $fields['flavor'] = new OptionsField('Flavor', [
            'conditions' => ['type' => 'php'],
            'optionName' => 'flavor',
            'options' => ['composer', 'drupal'],
            'default' => 'composer',
        ]);

        $fields['webroot'] = new Field('Web directory', [
            'conditions' => ['type' => 'php'],
            'optionName' => 'webroot',
            'default' => 'web',
            'validator' => function ($value) {
                if (preg_match('/^\/.*/', $value)) {
                    return 'The web root must not begin with a /. It is a directory relative to the application root.';
                }
                if (preg_match('/\w+/', $value)) {
                    return 'The web root must not contain spaces.';
                }
                return true;
            },
        ]);

        $fields['indexFile'] = new Field('Front controller', [
            'conditions' => ['type' => 'php'],
            'optionName' => 'indexFile',
            'default' => '/index.php',
            'validator' => function ($value) {
                if (!preg_match('/^\/.*/', $value)) {
                    return 'The front controller must be an absolute with path to a PHP file, starting with /.';
                }
                if (preg_match('/^\s*\.php/', $value)) {
                    return 'The front controller must end in .php and contain no spaces.';
                }
                return true;
            },
        ]);

        return $fields;
    }

    public function appYamlTemplate()
    {
        // @todo Replace this with a web service call to get a template off of GitHub.

        $template = <<<END
name: {app}
type: php:{php_version}

build:
    flavor: {flavor}

relationships:
    database: "mysql:mysql"
    solr: "solr:solr"
    redis: "redis:redis"

web:
    locations:
        "/":
            root: "{webroot}"
            passthru: "/{indexFile}"

disk: 2048
mounts:
    "/public/sites/default/files": "shared:files/files"
    "/tmp": "shared:files/tmp"
    "/private": "shared:files/private"

END;
        return $template;

    }
}
