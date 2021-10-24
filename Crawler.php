<?php
namespace App\Babel\Extension\codeforces;

use App\Babel\Crawl\CrawlerBase;
use App\Models\ProblemModel;
use App\Models\OJModel;
use App\Models\Eloquent\Problem;
use KubAT\PhpSimple\HtmlDomParser;
use Requests;
use Exception;
use Throwable;
use Log;

class Crawler extends CrawlerBase
{
    public $oid=null;
    public $prefix = "CF";
    private $currentProblemCcode;
    private $imageIndex;

    public function start($conf)
    {
        $action = $conf["action"];
        $con = $conf["con"];
        $cached = $conf["cached"];
        $range = $conf["range"];
        $this->oid = OJModel::oid('codeforces');

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
        $problemModel = new ProblemModel();
        $arr = $problemModel->getSolvedCount($this->oid);
        usort($arr, ["CrawlerBase", "cmp"]);
        $m = count($arr) / 10;
        for ($i = 1; $i <= count($arr); $i++) {
            $level = ceil($i / $m);
            $problemModel->updateDifficulty($arr[$i-1][0], $level);
        }
    }

    private function resetProblem()
    {
        foreach ($this->pro as $x => $y) {
            $this->pro[$x] = null;
        }
    }

    public function extractCodeForces($pcode, $url, $retries = 5)
    {
        $failed = true;
        $status = false;

        foreach (range(1, $retries) as $tries) {
            try {
                $status = $this->_extractCodeForces($pcode, $url);
            } catch (Throwable $e) {
                Log::alert($e);
                $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>{$e->getMessage()}</>\n");
                continue;
            }
            $failed = false;
            break;
        }

        if ($failed) {
            throw new Exception('Failed after multiple tries.');
        }

        return $status;
    }

    private function _extractCodeForces($pcode, $url)
    {
        $this->currentProblemCcode = $pcode;
        $this->imageIndex = 1;

        $response = $this->getCodeForcesResponse($url);
        $contentType = $response->headers['content-type'];
        $content = $response->body;

        if (stripos($content, "<title>Attachments") !== false) {
            // refetching actual attachment
            $attachmentDOM = HtmlDomParser::str_get_html($content, true, true, DEFAULT_TARGET_CHARSET, false);
            $attachmentURL = $attachmentDOM->find('#pageContent div.datatable tbody tr a', 0)->href;
            $url = $this->globalizeCodeForcesURL($attachmentURL);
            $response = $this->getCodeForcesResponse($url);
            $contentType = $response->headers['content-type'];
            $content = $response->body;
        }

        if (stripos($content, "<title>Codeforces</title>") !== false) {
            $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>Problem not found.</>\n");
            return false;
        }

        if (strpos($content, 'Statement is not available on English language') !== false) {
            $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>Statement is not available on English.</>\n");
            return false;
        }

        if (stripos($contentType, "text/html") !== false) {
            $this->pro["file"] = 0;
            $this->pro["file_url"] = null;

            $problemDOM = HtmlDomParser::str_get_html($content, true, true, DEFAULT_TARGET_CHARSET, false);

            $this->pro["source"] = trim($problemDOM->find('#sidebar th.left', 0)->plaintext);

            $this->pro["time_limit"] = sscanf($problemDOM->find('div.problem-statement div.header .time-limit', 0)->find('text', -1)->plaintext, "%d second")[0] * 1000;
            $this->pro["memory_limit"] = sscanf($problemDOM->find('div.problem-statement div.header .memory-limit', 0)->find('text', -1)->plaintext, "%d megabyte")[0] * 1024;

            $this->pro["input_type"] = $problemDOM->find('div.problem-statement div.header .input-file', 0)->find('text', -1)->plaintext;
            $this->pro["output_type"] = $problemDOM->find('div.problem-statement div.header .output-file', 0)->find('text', -1)->plaintext;

            $problemDOM->find('div.problem-statement div.header', 0)->outertext = '';

            $inputSpecificationDOM = $problemDOM->find('div.problem-statement div.input-specification', 0);
            if (filled($inputSpecificationDOM)) {
                $inputSpecificationDOM->find('div.section-title', 0)->outertext = '';
                $this->pro["input"] = trim($inputSpecificationDOM->innertext);
                $inputSpecificationDOM->outertext = '';
            }

            $outputSpecificationDOM = $problemDOM->find('div.problem-statement div.output-specification', 0);
            if (filled($outputSpecificationDOM)) {
                $outputSpecificationDOM->find('div.section-title', 0)->outertext = '';
                $this->pro["output"] = trim($outputSpecificationDOM->innertext);
                $outputSpecificationDOM->outertext = '';
            }

            $noteDOM = $problemDOM->find('div.problem-statement div.note', 0);
            if(filled($noteDOM)) {
                $noteDOM->find('div.section-title', 0)->outertext = '';
                $this->pro["note"] = trim($noteDOM->innertext);
                $noteDOM->outertext = '';
            }

            $sampleTestsDOM = $problemDOM->find('div.problem-statement div.sample-tests', 0);

            if (filled($sampleTestsDOM)) {
                $sampleTestsDOM->find('div.section-title', 0)->outertext = '';
                $sampleCount = intval(count($sampleTestsDOM->find('pre')) / 2);
                $samples = [];
                for ($i = 0; $i < $sampleCount; $i++) {
                    $sampleInput = $sampleTestsDOM->find('pre')[$i * 2]->innertext;
                    $sampleOutput = $sampleTestsDOM->find('pre')[$i * 2 + 1]->innertext;
                    array_push($samples, [
                        "sample_input" => $sampleInput,
                        "sample_output" => $sampleOutput
                    ]);
                }
                $this->pro["sample"] = $samples;
                $sampleTestsDOM->outertext = '';
            }

            $descriptionSpecificationDOM = $problemDOM->find('div.problem-statement', 0);
            $this->pro["description"] = trim($descriptionSpecificationDOM->innertext);

            $this->pro["note"] = $this->cacheImage(HtmlDomParser::str_get_html($this->pro["note"], true, true, DEFAULT_TARGET_CHARSET, false));
            $this->pro["description"] = $this->cacheImage(HtmlDomParser::str_get_html($this->pro["description"], true, true, DEFAULT_TARGET_CHARSET, false));
            $this->pro["input"] = $this->cacheImage(HtmlDomParser::str_get_html($this->pro["input"], true, true, DEFAULT_TARGET_CHARSET, false));
            $this->pro["output"] = $this->cacheImage(HtmlDomParser::str_get_html($this->pro["output"], true, true, DEFAULT_TARGET_CHARSET, false));
        } else {
            if (stripos($contentType, "application/pdf") !== false) {
                $extension = "pdf";
            } elseif (stripos($contentType, "application/msword") !== false) {
                $extension = "doc";
            } elseif (stripos($contentType, "application/vnd.openxmlformats-officedocument.wordprocessingml.document") !== false) {
                $extension = "docx";
            } else {
                $extension = pathinfo($url, PATHINFO_EXTENSION);
            }
            $cacheDir = base_path("public/external/codeforces/$extension");
            if (!file_exists($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }
            file_put_contents(base_path("public/external/codeforces/$extension/$this->currentProblemCcode.$extension"), $content);
            $this->pro["description"] = '';
            $this->pro["file"] = 1;
            $this->pro["file_url"] = "/external/codeforces/$extension/$this->currentProblemCcode.$extension";
            $this->pro["sample"] = [];
        }
        return true;
    }

    private function getCodeForcesResponse($url)
    {
        return Requests::get($url, ['Referer' => 'https://codeforces.com'], [
            'verify' => babel_path("Cookies/cacert.pem"),
            'timeout' => 30
        ]);
    }

    private function globalizeCodeForcesURL($localizedURL)
    {
        if (strpos($localizedURL, '://') !== false) {
            $url = $localizedURL;
        } elseif ($localizedURL[0] == '/') {
            $url = 'https://codeforces.com' . $localizedURL;
        } else {
            $url = 'https://codeforces.com/' . $localizedURL;
        }
        return $url;
    }

    private function cacheImage($dom)
    {
        if (!$dom) return null;
        foreach ($dom->find('img') as $imageElement) {
            $imageURL = $imageElement->src;
            $url = $this->globalizeCodeForcesURL($imageURL);
            $imageResponse = $this->getCodeForcesResponse($url);
            $extensions = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/gif' => '.gif', 'image/bmp' => '.bmp'];
            if (isset($imageResponse->headers['content-type'])) {
                $extension = $extensions[$imageResponse->headers['content-type']];
            } else {
                $extension = pathinfo($imageElement->src, PATHINFO_EXTENSION);
            }
            $cachedImageName = $this->currentProblemCcode . '_' . ($this->imageIndex++) . $extension;
            $cacheDir = base_path("public/external/codeforces/img");
            if (!file_exists($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }
            file_put_contents(base_path("public/external/codeforces/img/$cachedImageName"), $imageResponse->body);
            $imageElement->src = '/external/codeforces/img/' . $cachedImageName;
        }
        return $dom;
    }

    private function getProblemSet($cached)
    {
        if ($cached) {
            $response = file_get_contents(__DIR__ . "/problemset.problems");
        } else {
            $response = $this->getCodeForcesResponse('https://codeforces.com/api/problemset.problems')->body;
            file_put_contents(__DIR__ . "/problemset.problems", $response);
        }
        return $response;
    }

    public function crawl($con, $cached, $incremental, $range)
    {
        $problemModel=new ProblemModel();

        try {
            $response = $this->getProblemSet($cached);
            $problemset = json_decode($response, true);
            if ($problemset["status"] != "OK") {
                throw new Exception("Contest list status not OK.");
            }
        } catch (Throwable $e) {
            throw new Exception('Failed fetching problem set.');
        }

        $startingMessage = $incremental ? 'Updating' : 'Crawling';
        $endingMessage = $incremental ? 'Updated' : 'Crawled';

        foreach(array_reverse($problemset['result']['problems'], true) as $index => $problem) {
            $this->resetProblem();

            if ($con != 'all') {
                if ($con != $problem['contestId']) {
                    continue;
                }
            } elseif ($this->inRange($problem['contestId'], $range) === false) {
                continue;
            }

            $pcode = $this->prefix . $problem['contestId'].$problem['index'];

            if ($incremental && Problem::where('pcode', $pcode)->count()) {
                continue;
            }

            $this->line("<fg=yellow>$startingMessage:   </>$pcode");

            $this->pro['origin'] = "https://codeforces.com/contest/{$problem['contestId']}/problem/{$problem['index']}";
            $this->pro['title'] = str_replace('"', "'", $problem['name']);
            $this->pro['solved_count'] = $problemset['result']['problemStatistics'][$index]['solvedCount'];
            $this->pro['pcode'] = $pcode;
            $this->pro['index_id'] = $problem['index'];
            $this->pro['contest_id'] = $problem['contestId'];
            $this->pro['OJ'] = $this->oid;
            $this->pro['tot_score'] = 1;
            $this->pro['partial'] = 0;
            $this->pro['markdown'] = 0;

            if (!$this->extractCodeForces($this->pro['pcode'], $this->pro['origin'])) {
                continue;
            }

            $pid=$problemModel->pid($this->pro['pcode']);

            if ($pid) {
                $problemModel->clearTags($pid);
                $newPID = $this->updateProblem($this->oid);
            } else {
                $newPID = $this->insertProblem($this->oid);
            }

            for ($j=0; $j<count($problem['tags']); $j++) {
                $problemModel->addTags($newPID, $problem['tags'][$j]);
            }

            $this->line("<fg=green>$endingMessage:    </>$pcode");
        }
    }

    private function inRange($needle, $haystack)
    {
        $options = [];
        if(!is_null($haystack[0])) {
            $options['min_range'] = $haystack[0];
        }
        if(!is_null($haystack[1])) {
            $options['max_range'] = $haystack[1];
        }
        return filter_var($needle, FILTER_VALIDATE_INT, [
            'options' => $options
        ]);
    }
}
