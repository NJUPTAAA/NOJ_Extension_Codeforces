<?php
namespace App\Babel\Extension\template;

use App\Babel\Submit\Curl;
use App\Models\CompilerModel;
use App\Models\JudgerModel;
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
        $this->oid=OJModel::oid('template');
        if(is_null($this->oid)) {
            throw new Exception("Online Judge Not Found");
        }
        $judger_list=$judger->list($this->oid);
        $this->selectedJudger=$judger_list[array_rand($judger_list)];
    }

    private function _login()
    {
        $response=$this->grab_page([
            "site"=>'http://poj.org',
            "oj"=>'poj',
            "handle"=>$this->selectedJudger["handle"]
        ]);
        if (strpos($response, 'Log Out')===false) {
            $params=[
                'user_id1' => $this->selectedJudger["handle"],
                'password1' => $this->selectedJudger["password"],
                'B1' => 'login',
            ];
            $this->login([
                "url"=>'http://poj.org/login',
                "data"=>http_build_query($params),
                "oj"=>'poj',
                "ret"=>true,
                "handle"=>$this->selectedJudger["handle"]
            ]);
        }
    }

    private function _submit()
    {
        $params=[
            'problem_id' => $this->post_data['iid'],
            'language' => $this->post_data['lang'],
            'source' => base64_encode($this->post_data["solution"]),
            'encoded' => 1, // Optional, but sometimes base64 seems smaller than url encode
        ];

        $response=$this->post_data([
            "site"=>"http://poj.org/submit",
            "data"=>http_build_query($params),
            "oj"=>"poj",
            "ret"=>true,
            "follow"=>false,
            "returnHeader"=>true,
            "postJson"=>false,
            "extraHeaders"=>[],
            "handle"=>$this->selectedJudger["handle"]
        ]);

        if (!preg_match('/Location: .*\/status/', $response, $match)) {
            $this->sub['verdict']='Submission Error';
        } else {
            $res=Requests::get('http://poj.org/status?problem_id='.$this->post_data['iid'].'&user_id='.urlencode($this->selectedJudger["handle"]));
            if (!preg_match('/<tr align=center><td>(\d+)<\/td>/', $res->body, $match)) {
                $this->sub['verdict']='Submission Error';
            } else {
                $this->sub['remote_id']=$match[1];
            }
        }
    }

    public function submit()
    {
        $validator=Validator::make($this->post_data, [
            'pid' => 'required|integer',
            'coid' => 'required|integer',
            'iid' => 'required|integer',
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
