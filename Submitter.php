<?php
namespace App\Babel\Extension\codeforces;

use App\Babel\Submit\Curl;
use App\Models\CompilerModel;
use App\Models\JudgerModel;
use App\Models\OJModel;
use App\Models\Submission\SubmissionModel;
use Illuminate\Support\Facades\Validator;
use Requests;

class Submitter extends Curl
{
    protected $sub;
    public $post_data=[];
    protected $oid;
    protected $selectedJudger;

    public function __construct(& $sub, $all_data)
    {
        $this->sub=& $sub;
        $this->post_data=$all_data;
        $judger=new JudgerModel();
        $this->oid=OJModel::oid('codeforces');
        if(is_null($this->oid)) {
            throw new Exception("Online Judge Not Found");
        }
        $judger_list=$judger->list($this->oid);
        $this->selectedJudger=$judger_list[array_rand($judger_list)];
    }

    private function _login()
    {
        $response=$this->grab_page([
            'site' => 'https://codeforces.com',
            'oj' => 'codeforces',
            'handle' => $this->selectedJudger["handle"],
        ]);
        if (!(strpos($response, 'Logout')!==false)) {
            $response=$this->grab_page([
                'site' => 'https://codeforces.com/enter',
                'oj' => 'codeforces',
                'handle' => $this->selectedJudger["handle"],
            ]);

            $exploded=explode("name='csrf_token' value='", $response);
            $token=explode("'/>", $exploded[2])[0];

            $params=[
                'csrf_token' => $token,
                'action' => 'enter',
                'ftaa' => '',
                'bfaa' => '',
                'handleOrEmail' => $this->selectedJudger["handle"], //I wanna kill for handleOrEmail
                'password' => $this->selectedJudger["password"],
                'remember' => true,
            ];
            $this->login([
                'url' => 'https://codeforces.com/enter',
                'data' => http_build_query($params),
                'oj' => 'codeforces',
                'handle' => $this->selectedJudger["handle"],
            ]);
        }
    }

    private function _submit()
    {
        $submissionModel=new SubmissionModel();
        $s_num=$submissionModel->countSolution($this->post_data["solution"]);
        $space='';
        for ($i=0; $i<$s_num; $i++) {
            $space.=' ';
        }
        $contestId=$this->post_data["cid"];
        $submittedProblemIndex=$this->post_data["iid"];
        $programTypeId=$this->post_data["lang"];
        $source=($this->post_data["solution"].$space.chr(10));


        $response=$this->grab_page([
            'site' => "https://codeforces.com/contest/{$this->post_data['cid']}/submit",
            'oj' => "codeforces",
            'handle' => $this->selectedJudger["handle"],
        ]);

        $exploded=explode("name='csrf_token' value='", $response);
        $token=explode("'/>", $exploded[2])[0];

        $params=[
            'csrf_token' => $token,
            'action' => 'submitSolutionFormSubmitted',
            'ftaa' => '',
            'bfaa' => '',
            'submittedProblemIndex' => $submittedProblemIndex,
            'programTypeId' => $programTypeId,
            'source' => $source,
            'tabSize' => 4,
            'sourceFile' => '',
        ];
        $response=$this->post_data([
            'site' => "https://codeforces.com/contest/{$this->post_data['cid']}/submit?csrf_token=".$token,
            'data' => http_build_query($params),
            'oj' => "codeforces",
            'ret' => true,
            'returnHeader' => true,
            'handle' => $this->selectedJudger["handle"],
        ]);
        $this->sub["jid"]=$this->selectedJudger["jid"];
        if (strpos($response, 'alert("Source code hasn\'t submitted because of warning, please read it.");')!==false) {
            $this->sub['verdict']='Compile Error';
            preg_match('/<div class="roundbox " style="font-size:1.2rem;margin:0.5em 0;padding:0.5em;text-align:left;background-color:#eca;">[\s\S]*?<div class="roundbox-rb">&nbsp;<\/div>([\s\S]*?)<div/', $response, $match);
            $warning=str_replace('Press button to submit the solution.', '', $match[1]);
            $this->sub['compile_info']=trim($warning);
        } elseif (substr_count($response, 'My Submissions')!=2) {
            // Forbidden?
            $exploded=explode('<span class="error for__source">', $response);
            if (!isset($exploded[1])) {
                $this->sub['verdict']="Submission Error";
            } else {
                $this->sub['compile_info']=explode("</span>", $exploded[1])[0];
                $this->sub['verdict']="Compile Error";
            }
        } else {
            preg_match('/submissionId="(\d+)"/', $response, $match);
            $this->sub['remote_id']=$match[1];
        }
    }

    public function submit()
    {
        $validator=Validator::make($this->post_data, [
            'pid' => 'required|integer',
            'cid' => 'required|integer',
            'coid' => 'required|integer',
            'iid' => 'required',
            'solution' => 'required',
        ]);

        if ($validator->fails()) {
            $this->sub['verdict']="System Error";
            return;
        }

        $this->_login();
        $this->_submit();
    }
}
