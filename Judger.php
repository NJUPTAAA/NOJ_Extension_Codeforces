<?php
namespace App\Babel\Extension\codeforces;

use App\Babel\Submit\Curl;
use App\Models\Submission\SubmissionModel;
use App\Models\JudgerModel;
use App\Models\OJModel;
use Requests;
use Exception;
use Log;

class Judger extends Curl
{

    public $verdict=[
        "COMPILATION_ERROR"=>"Compile Error",
        "RUNTIME_ERROR"=> "Runtime Error",
        "WRONG_ANSWER"=> "Wrong Answer",
        "TIME_LIMIT_EXCEEDED"=>"Time Limit Exceed",
        "OK"=>"Accepted",
        "MEMORY_LIMIT_EXCEEDED"=>"Memory Limit Exceed",
        "PRESENTATION_ERROR"=>"Presentation Error",
        "IDLENESS_LIMIT_EXCEEDED"=>"Idleness Limit Exceed"
    ];
    private $list = [];
    private $csrf = [];
    private $handles = [];

    public function __construct()
    {
        $this->submissionModel = new SubmissionModel();
        $this->judgerModel = new JudgerModel();
        $this->oid=OJModel::oid('codeforces');

        if(is_null($this->oid)) {
            throw new Exception("Online Judge Not Found");
        }
    }

    public function judge($row)
    {
        $cf=[];
        if (!isset($this->handles[$row['jid']])) {
            $handle = $this->judgerModel->detail($row['jid'])['handle'];
            $this->handles[$row['jid']] = $handle;
            $res=$this->grab_page([
                'site' => 'https://codeforces.com',
                'oj' => 'codeforces',
                'handle' => $handle,
            ]);
            preg_match('/<meta name="X-Csrf-Token" content="([0-9a-z]*)"/', $res, $match);
            $this->csrf[$row['jid']] = $match[1];
            $this->list = array_merge($this->list, $this->get_last_codeforces($this->submissionModel->countEarliestWaitingSubmission($this->oid) + 5, $handle));
        }
        foreach ($this->list as $c) {
            if ($c[3]==$row["remote_id"]) {
                $cf=$c;
                break;
            }
        }
        if (empty($cf)) {

            // $this->MODEL->updateSubmission($row['sid'], ['verdict'=>"Submission Error"]);
        } else {
            if (isset($this->verdict[$cf[2]])) {
                $sub=[];
                $sub['verdict']=$this->verdict[$cf[2]];
                if ($sub['verdict']=='Compile Error') {
                    $res=$this->post_data([
                        'site' => 'https://codeforces.com/data/judgeProtocol',
                        'data' => [
                            'submissionId'=>$row['remote_id'],
                            'csrf_token'=>$this->csrf[$row['jid']],
                        ],
                        'oj' => 'codeforces',
                        'ret' => true,
                        'returnHeader' => false,
                        'handle' => $this->handles[$row['jid']],
                    ]);
                    $sub['compile_info']=json_decode($res);
                }
                $sub["score"]=$sub['verdict']=="Accepted" ? 1 : 0;
                $sub['time']=$cf[0];
                $sub['memory']=$cf[1];
                $sub['remote_id']=$cf[3];

                // $ret[$row['sid']]=[
                //     "verdict"=>$sub['verdict']
                // ];

                $this->submissionModel->updateSubmission($row['sid'], $sub);
            }
        }
    }

    private function get_last_codeforces($num, $handle)
    {
        // \Log::debug('num:'.$num);
        $ret=array();
        if ($num==0) {
            return $ret;
        }

        $ch=curl_init();
        $url="https://codeforces.com/api/user.status?handle={$handle}&from=1&count={$num}";
        curl_setopt($ch, CURLOPT_CAINFO, babel_path("Cookies/cacert.pem"));
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response=curl_exec($ch);
        // \Log::debug('curl error:' . curl_error($ch));
        curl_close($ch);
        // \Log::debug('res:' . $response);
        $result=json_decode($response, true);
        if ($result["status"]=="OK") {
            for ($i=0; $i<$num; $i++) {
                if (!isset($result["result"][$i]["verdict"])) {
                    return array_reverse($ret);
                }
                array_push($ret, array($result["result"][$i]["timeConsumedMillis"], $result["result"][$i]["memoryConsumedBytes"] / 1000, $result["result"][$i]["verdict"], $result["result"][$i]["id"]));
            }
        }
        return array_reverse($ret);
    }
}
