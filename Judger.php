<?php
namespace App\Babel\Extension\codeforces;

use App\Babel\Submit\Curl;
use App\Models\SubmissionModel;
use App\Models\JudgerModel;
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
    private $list;
    private $csrf=[];

    public function __construct()
    {
        $this->submissionModel=new SubmissionModel();
        $this->judgerModel=new JudgerModel();
        $this->list=$this->get_last_codeforces($this->submissionModel->countEarliestWaitingSubmission(2)+100);
    }

    public function judge($row)
    {
        $cf=[];
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
                    $handle=$this->judgerModel->detail($row['jid'])['handle'];
                    if (!isset($this->csrf[$handle])) {
                        $res=$curl->grab_page([
                            'site' => 'http://codeforces.com',
                            'oj' => 'codeforces',
                            'handle' => $handle,
                        ]);
                        preg_match('/<meta name="X-Csrf-Token" content="([0-9a-z]*)"/', $res, $match);
                        $this->csrf[$handle]=$match[1];
                    }
                    $res=$curl->post_data([
                        'site' => 'http://codeforces.com/data/judgeProtocol',
                        'data' => [
                            'submissionId'=>$row['remote_id'],
                            'csrf_token'=>$this->csrf[$handle],
                        ],
                        'oj' => 'codeforces',
                        'ret' => true,
                        'returnHeader' => false,
                        'handle' => $handle,
                    ]);
                    $sub['compile_info']=json_decode($res);
                }
                $sub["score"]=$sub['verdict']=="Accepted" ? 1 : 0;
                $sub['time']=$cf[0];
                $sub['memory']=$cf[1];
                $sub['remote_id']=$cf[3];

                $ret[$row['sid']]=[
                    "verdict"=>$sub['verdict']
                ];

                $this->submissionModel->updateSubmission($row['sid'], $sub);
            }
        }
    }

    private function get_last_codeforces($num)
    {
        $ret=array();
        if ($num==0) {
            return $ret;
        }

        $judger_list=$this->judgerModel->list(2);
        $judgerName=$judger_list[array_rand($judger_list)]['handle'];

        $ch=curl_init();
        $url="http://codeforces.com/api/user.status?handle={$judgerName}&from=1&count={$num}";
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response=curl_exec($ch);
        curl_close($ch);
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
