<?php

namespace App\Babel\Extension\codeforces;

use App\Babel\Biblioteca\BibliotecaBase;
use App\Models\Services\OJService;
use App\Models\Services\ProblemService;
use App\Models\Eloquent\ProblemDialect;
use Exception;

class Biblioteca extends BibliotecaBase
{
    private $bibliotecaNamespace = 'CodeForces';

    public function run($conf)
    {
        $pcode = $conf["pcode"];
        $dialect = $conf["dialect"];

        if (blank(OJService::oid('codeforces'))) {
            throw new Exception("Online Judge Not Found");
        }

        $this->getFromBiblioteca($pcode, $dialect);
    }

    private function getFromBiblioteca($pcode, $dialect)
    {
        $pid = ProblemService::pid($pcode);
        if (blank($pid)) {
            throw new Exception("Problem Code Not Found");
        }

        $catalog = json_decode(file_get_contents("{$this->bibliotecaUrl}/{$this->bibliotecaNamespace}/catalog.min.json"), true);
        if (!isset($catalog[$pcode]) || blank($catalog[$pcode])) {
            throw new Exception("Problem Not Found on Biblioteca");
        }

        foreach (json_decode(file_get_contents("{$this->bibliotecaUrl}/{$this->bibliotecaNamespace}/$pcode.min.json"), true) as $language => $dialectInfo) {
            if ($dialect != 'all' && $dialect != $language) {
                continue;
            }
            $action = 'Updat';
            $dialectInstance = ProblemDialect::where(['problem_id' => $pid, 'dialect_language' => $language, 'is_biblioteca' => true])->first();
            if (blank($dialectInstance)) {
                $dialectInstance = new ProblemDialect();
                $action = 'Fetch';
            }
            $this->line("<fg=yellow>{$action}ing</> $language of $pcode");
            $dialectInstance->problem_id = $pid;
            $dialectInstance->dialect_name = "Biblioteca Translation for $language";
            $dialectInstance->dialect_language = $language;
            $dialectInstance->is_biblioteca = true;
            foreach (['title', 'description', 'input', 'output', 'note'] as $fields) {
                if (isset($dialectInfo[$fields]) && filled($dialectInfo[$fields])) {
                    $dialectInstance->$fields = $dialectInfo[$fields];
                } else {
                    $dialectInstance->$fields = null;
                }
            }
            $dialectInstance->copyright = "Biblioteca la Babel";
            $dialectInstance->save();
            $this->line("<fg=green>{$action}ed </> $language of $pcode");
        }
    }
}
