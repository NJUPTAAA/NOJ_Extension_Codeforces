<?php
namespace App\Babel\Extension\codeforces;

use App\Babel\Install\InstallerBase;
use Exception;

class Installer extends InstallerBase
{
    public $ocode="codeforces";

    public function install()
    {
        // throw new Exception("No Install Method Provided");
        $this->_install($this->ocode);
    }

    public function uninstall()
    {
        // throw new Exception("No Uninstall Method Provided");
        $this->_uninstall($this->ocode);
    }
}
