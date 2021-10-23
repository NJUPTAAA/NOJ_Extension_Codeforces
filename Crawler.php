<?php
namespace App\Babel\Extension\codeforces;

use App\Babel\Crawl\CrawlerBase;
use App\Models\ProblemModel;
use App\Models\OJModel;
use KubAT\PhpSimple\HtmlDomParser;
use Requests;
use Exception;

class Crawler extends CrawlerBase
{
    public $oid=null;
    public $prefix="Codeforces";
    private $con;
    private $imgi;
    /**
     * Initial
     *
     * @return Response
     */
    public function start($conf)
    {
        $action=$conf["action"];
        $con=$conf["con"];
        $cached=$conf["cached"];
        $range=$conf["range"];
        $this->oid=OJModel::oid('codeforces');

        if(is_null($this->oid)) {
            throw new Exception("Online Judge Not Found");
        }

        if ($action=='judge_level') {
            $this->judge_level();
        } else {
            $this->crawl($con, $cached, $action == 'update_problem', $range);
        }
    }

    public function judge_level()
    {
        $problemModel=new ProblemModel();
        $arr=$problemModel->getSolvedCount($this->oid);
        usort($arr, ["CrawlerBase", "cmp"]);
        $m=count($arr) / 10;
        for ($i=1; $i<=count($arr); $i++) {
            $level=ceil($i / $m);
            $problemModel->updateDifficulty($arr[$i-1][0], $level);
        }
    }

    public function extractCodeForces($cid, $num, $url, $default_desc="", $retries=5)
    {
        $attempts=1;
        $ret=false;
        while($attempts <= $retries){
            try{
                $ret=$this->_extractCodeForces($cid, $num, $url, $default_desc);
            }catch(Exception $e){
                $attempts++;
                $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>{$e->getMessage()}</>\n");
                continue;
            }
            break;
        }
        if($attempts==6){
            throw new Exception("Failed After Multiple Tries");
        }
        return $ret;
    }

    private function _extractCodeForces($cid, $num, $url, $default_desc)
    {
        $pid = $cid.$num;
        $this->con = $pid;
        $this->imgi = 1;
        $content = $this->getUrl($url);
        $content_type = Requests::get($url)->headers['content-type'];
        if (stripos($content, "<title>Codeforces</title>")===false) {
            if (strpos($content, 'Statement is not available on English language') !== false) {
                $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>Statement is not available on English.</>\n");
                return false;
            }
            if (stripos($content, "<title>Attachments")!==false) {
                $this->pro["description"].=$default_desc;
            } else {
                if (stripos($content_type, "text/html")!==false) {
                    $this->pro["file"]=0;
                    $this->pro["file_url"]='';
                    $first_step=explode('<div class="input-file"><div class="property-title">input</div>', $content);
                    $second_step=explode("</div>", $first_step[1]);
                    $this->pro["input_type"]=$second_step[0];
                    $first_step=explode('<div class="output-file"><div class="property-title">output</div>', $content);
                    $second_step=explode("</div>", $first_step[1]);
                    $this->pro["output_type"]=$second_step[0];

                    if (preg_match("/time limit per test<\\/div>(.*) second/sU", $content, $matches)) {
                        $this->pro["time_limit"]=intval(trim($matches[1])) * 1000;
                    }
                    if (preg_match("/memory limit per test<\\/div>(.*) megabyte/sU", $content, $matches)) {
                        $this->pro["memory_limit"]=intval(trim($matches[1])) * 1024;
                    }
                    if (preg_match("/output<\\/div>.*<div>(.*)<\\/div>/sU", $content, $matches)) {
                        $this->pro["description"].=str_replace('$$$$$$', '$$', trim(($matches[1])));
                    }
                    if (preg_match("/Input<\\/div>(.*)<\\/div>/sU", $content, $matches)) {
                        $this->pro["input"]=str_replace('$$$$$$', '$$', trim($matches[1]));
                    }
                    if (preg_match("/Output<\\/div>(.*)<\\/div>/sU", $content, $matches)) {
                        $this->pro["output"]=str_replace('$$$$$$', '$$', trim($matches[1]));
                    }

                    if (strpos($content, '<div class="sample-test">')!==false) {
                        $temp_sample=explode('<div class="sample-test">', $content)[1];
                        if (!(strpos($content, '<div class="note">')!==false)) {
                            $temp_sample=explode('<script type="text/javascript">', $temp_sample)[0];
                        } else {
                            $temp_sample=explode('<div class="note">', $temp_sample)[0];
                        }

                        $sample_list=HtmlDomParser::str_get_html($temp_sample, true, true, DEFAULT_TARGET_CHARSET, false);
                        $sample_pairs=intval(count($sample_list->find('pre')) / 2);
                        $this->pro["sample"]=[];
                        for ($i=0; $i<$sample_pairs; $i++) {
                            $sample_input=$sample_list->find('pre')[$i * 2]->innertext;
                            $sample_output=$sample_list->find('pre')[$i * 2+1]->innertext;
                            array_push($this->pro["sample"], [
                                "sample_input"=>$sample_input,
                                "sample_output"=>$sample_output
                            ]);
                        }
                    }

                    if (preg_match("/Note<\\/div>(.*)<\\/div><\\/div>/sU", $content, $matches)) {
                        $this->pro["note"]=trim(($matches[1]));
                        $this->pro["note"]=$this->cacheImage(HtmlDomParser::str_get_html($this->pro["note"], true, true, DEFAULT_TARGET_CHARSET, false));
                    }
                    if (preg_match("/<th class=\"left\" style=\"width:100%;\">(.*)<\\/th>/sU", $content, $matches)) {
                        $this->pro["source"]=trim(strip_tags($matches[1]));
                    }

                    $this->pro["description"]=$this->cacheImage(HtmlDomParser::str_get_html($this->pro["description"], true, true, DEFAULT_TARGET_CHARSET, false));
                    $this->pro["input"]=$this->cacheImage(HtmlDomParser::str_get_html($this->pro["input"], true, true, DEFAULT_TARGET_CHARSET, false));
                    $this->pro["output"]=$this->cacheImage(HtmlDomParser::str_get_html($this->pro["output"], true, true, DEFAULT_TARGET_CHARSET, false));
                } else {
                    if (stripos($content_type, "application/pdf")!==false) {
                        $ext="pdf";
                    } elseif (stripos($content_type, "application/msword")!==false) {
                        $ext="doc";
                    } elseif (stripos($content_type, "application/vnd.openxmlformats-officedocument.wordprocessingml.document")!==false) {
                        $ext="docx";
                    }
                    $dir=base_path("public/external/gym");
                    if (!file_exists($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    file_put_contents(base_path("public/external/gym/$cid$num.$ext"), $content);
                    $this->pro["description"]='';
                    $this->pro["time_limit"]=0;
                    $this->pro["memory_limit"]=0;
                    $this->pro["source"]="Here";
                    $this->pro["file"]=1;
                    $this->pro["file_url"]="/external/gym/$cid$num.$ext";
                    $this->pro["sample"]=[];
                }
            }
        } else {
            $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>Problem not found.</>\n");
            return false;
        }
        return true;
    }

    private function cacheImage($dom)
    {
        if(!$dom) return $dom;
        foreach ($dom->find('img') as $ele) {
            $src = $ele->src;
            if (strpos($src, '://') !== false) {
                $url=$src;
            } elseif ($src[0]=='/') {
                $url='https://codeforces.com'.$src;
            } else {
                $url='https://codeforces.com/'.$src;
            }
            $res=Requests::get($url, ['Referer' => 'https://codeforces.com'], [
                'verify' => babel_path("Cookies/cacert.pem")
            ]);
            $ext=['image/jpeg'=>'.jpg', 'image/png'=>'.png', 'image/gif'=>'.gif', 'image/bmp'=>'.bmp'];
            if (isset($res->headers['content-type'])) {
                $cext=$ext[$res->headers['content-type']];
            } else {
                $pos=strpos($ele->src, '.');
                if ($pos===false) {
                    $cext='';
                } else {
                    $cext=substr($ele->src, $pos);
                }
            }
            $fn=$this->con.'_'.($this->imgi++).$cext;
            $dir=base_path("public/external/codeforces/img");
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents(base_path("public/external/codeforces/img/$fn"), $res->body);
            $ele->src='/external/codeforces/img/'.$fn;
        }
        return $dom;
    }

    public function crawl($con, $cached, $incremental, $range)
    {
        $problemModel=new ProblemModel();
        $start=time();
        if ($cached) {
            $response=file_get_contents(__DIR__."/problemset.problems");
        } else {
            $ch=curl_init();
            $url="https://codeforces.com/api/problemset.problems";
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CAINFO, babel_path("Cookies/cacert.pem"));
            $response=curl_exec($ch);
            curl_close($ch);
            // cache to folder
            $fp=fopen(__DIR__."/problemset.problems", "w");
            fwrite($fp, $response);
            fclose($fp);
        }
        $result=json_decode($response, true);
        $updmsg = $incremental ? 'Updating' : 'Crawling';
        $donemsg = $incremental ? 'Updated' : 'Crawled';
        if ($result["status"]!="OK") {
            $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>Failed fetching problem set.</>\n");
            return;
        }
        for ($i=count($result['result']['problems'])-1; $i>=0; $i--) {
            foreach ($this->pro as $x => $y) {
                $this->pro[$x]='';
            }

            if ($con!='all') {
                if ($con!=$result['result']['problems'][$i]['contestId']) {
                    continue;
                }
            } elseif ($this->inRange($result['result']['problems'][$i]['contestId'], $range)===false) {
                continue;
            }

            $pcode = "CF".$result['result']['problems'][$i]['contestId'].$result['result']['problems'][$i]['index'];

            if ($incremental && !empty($problemModel->basic($problemModel->pid($pcode)))) {
                continue;
            }

            $this->line("<fg=yellow>{$updmsg}:   </>$pcode");

            $this->pro['origin']="https://codeforces.com/contest/{$result['result']['problems'][$i]['contestId']}/problem/{$result['result']['problems'][$i]['index']}";
            $this->pro['title']=str_replace('"', "'", $result['result']['problems'][$i]['name']);
            $this->pro['solved_count']=$result['result']['problemStatistics'][$i]['solvedCount'];
            $this->pro['pcode']=$pcode;
            $this->pro['index_id']=$result['result']['problems'][$i]['index'];
            $this->pro['contest_id']=$result['result']['problems'][$i]['contestId'];
            $this->pro['OJ']=$this->oid;
            $this->pro['tot_score']=1;
            $this->pro['partial']=0;
            $this->pro['markdown']=0;

            if (!$this->extractCodeForces($this->pro['contest_id'], $this->pro['index_id'], $this->pro['origin'])) {
                continue;
            }

            $pid=$problemModel->pid($this->pro['pcode']);

            if ($pid) {
                $problemModel->clearTags($pid);
                $new_pid=$this->updateProblem($this->oid);
            } else {
                $new_pid=$this->insertProblem($this->oid);
            }

            for ($j=0; $j<count($result['result']['problems'][$i]['tags']); $j++) {
                $problemModel->addTags($new_pid, $result['result']['problems'][$i]['tags'][$j]);
            }

            // Why not foreach ?????? I don't know...

            $this->line("<fg=green>$donemsg:    </>$pcode");
        }
    }

    private function inRange($needle, $haystack)
    {
        $options=[];
        if(!is_null($haystack[0])) {
            $options['min_range']=$haystack[0];
        }
        if(!is_null($haystack[1])) {
            $options['max_range']=$haystack[1];
        }
        return filter_var($needle, FILTER_VALIDATE_INT, [
            'options' => $options
        ]);
    }
}
