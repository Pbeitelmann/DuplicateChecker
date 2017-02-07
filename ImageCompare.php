<?php
require 'vendor/autoload.php';

class ImageCompare
{
    private $config;

    /**
     * @var PDO
     */
    private $lfDb;

    /**
     * @var PDO
     */
    private $plDb;

    /**
     * @var PDO
     */
    private $tkDb;

    /**
     * @var PDO
     */
    private $tkDevDb;

    /**
     * @var array
     */
    private $hashArray;


    public function execute()
    {
        $this->config = parse_ini_file("imageCompare.conf", true);
        $this->prepareDbHandles();
        $this->logImageHashes();
        $this->processResults();
        $this->saveResultImages();
    }

    /**
     * @return \Aws\S3\S3Client
     */
    private function getLfS3Client()
    {
        $lfS3Client = new Aws\S3\S3Client([
            'version' => 'latest',
            'region' => 'eu-west-1',
            'credentials' => [
                'key' => $this->config['lieferandoS3']['key'],
                'secret' => $this->config['lieferandoS3']['secret'],
            ],
        ]);
        return $lfS3Client;
    }

    /**
     * @return \Aws\S3\S3Client
     */
    private function getPlS3Client()
    {
        $tkS3Client = new Aws\S3\S3Client([
            'version' => 'latest',
            'region' => 'eu-west-1',
            'credentials' => [
                'key' => $this->config['pyszneS3']['key'],
                'secret' => $this->config['pyszneS3']['secret'],
            ],
        ]);
        return $tkS3Client;
    }

    /**
     * @desc Prepares the database handles for later usage
     */
    protected function prepareDbHandles() {
        $lfDsn = "mysql:host=" . $this->config["lieferando"]["host"] . ";dbname=" . $this->config["lieferando"]["dbName"];
        $this->lfDb = new PDO($lfDsn, $this->config["lieferando"]["user"], $this->config["lieferando"]["password"], [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->lfDb->exec('SET CHARACTER SET utf8');

        $tkDsn = "mysql:host=" . $this->config["takeaway"]["host"] . ";dbname=" . $this->config["takeaway"]["dbName"];
        $this->tkDb = new PDO($tkDsn, $this->config["takeaway"]["user"], $this->config["takeaway"]["password"], [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->tkDb->exec('SET CHARACTER SET utf8');

        $tkDevDsn = "mysql:host=" . $this->config["takeawayDev"]["host"] . ";dbname=" . $this->config["takeawayDev"]["dbName"];
        $this->tkDevDb = new PDO($tkDevDsn, $this->config["takeawayDev"]["user"], $this->config["takeawayDev"]["password"], [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->tkDevDb->exec('SET CHARACTER SET utf8');

        $plDsn = "mysql:host=" . $this->config["pyszne"]["host"] . ";dbname=" . $this->config["pyszne"]["dbName"];
        $this->plDb = new PDO($plDsn, $this->config["pyszne"]["user"], $this->config["pyszne"]["password"], [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->plDb->exec('SET CHARACTER SET utf8');
    }

    /**
     * @desc Gets all lieferando category pictures of active restaurants and hashes them.
     *       The hash is set as an array key. If another category has the same image, it will be set on the
     *       same array key, incrementing it, thus indicating a duplicated category Image
     */
    public function logImageHashes()
    {
        $lfFetch = $this->plDb->prepare("SELECT `id`
                                            FROM `restaurants`
                                            WHERE `status`
                                            NOT IN ('11','18', '19', '30', '31')
                                            AND `id` > 19936");
        $lfFetch->execute();
        $activeRestaurantIds = $lfFetch->fetchAll();

        foreach($activeRestaurantIds as $restaurantId)
        {
            echo("Checking Restaurant with ID:" . $restaurantId["id"] . PHP_EOL);
            $categoryFetch = $this->plDb->prepare("SELECT * FROM meal_categories WHERE restaurantId = :id");
            $categoryFetch->bindParam(":id", $restaurantId["id"]);
            $categoryFetch->execute();
            $lfCategories = $categoryFetch->fetchAll();

            $i = 0;
            foreach($lfCategories as $lfCategory)
            {
                $categoryPictureFetch = $this->plDb->prepare("SELECT * from category_picture where id = :id");
                $categoryPictureFetch->bindParam(":id", $lfCategory["categoryPictureId"]);
                $categoryPictureFetch->execute();
                $categoryPicture = $categoryPictureFetch->fetchAll();

                if(isset($categoryPicture)) {
                    $data = $this->downloadLfCategoryImage($lfCategory["categoryPictureId"]);

                    //check what data actually contains
                    if(!empty($data)) {
                        if(isset($this->hashArray[md5($data)]))
                        {
                            $this->hashArray[md5($data)]["occurrences"]++;
                        } else {
                            $this->hashArray[md5($data)]["occurrences"] = 1;
                        }

                        if(isset($this->hashArray[md5($data)]["categoryId"])) {
                            $this->hashArray[md5($data)]["categoryId"] .= ", " . $lfCategory["id"];

                        } else {
                            $this->hashArray[md5($data)]["categoryId"] = $lfCategory["id"];
                        }

                        if(isset($this->hashArray[md5($data)]["categoryPictureId"])) {
                            $this->hashArray[md5($data)]["categoryPictureId"] .= ", " . $categoryPicture[0]["id"];
                        } else {
                            $this->hashArray[md5($data)]["categoryPictureId"] = $categoryPicture[0]["id"];
                        }

                        if(isset($this->hashArray[md5($data)]["restaurantId"])) {
                            $this->hashArray[md5($data)]["restaurantId"] .= ", " . $restaurantId["id"];
                        } else {
                            $this->hashArray[md5($data)]["restaurantId"] =  $restaurantId["id"];
                        }


                    }
                }
                $this->progressBar(++$i, sizeof($lfCategories));
            }
            echo(PHP_EOL);
            $this->saveToFile($this->hashArray);
        }
    }

    /**
     * @desc Donwloads a lieferando category image from S3
     * @param $categoryPictureId
     * @return null|string
     */
    private function downloadLfCategoryImage($categoryPictureId)
    {
        $bucket = $this->config['pyszneS3']['bucket'];
        $prefix = "category_pictures/{$categoryPictureId}/";

        $s3Iterator = $this->getPlS3Client()->getIterator('ListObjects', [
            'Bucket' => $bucket,
            'Prefix' => $prefix
        ]);

        $imagePath = null;
        foreach ($s3Iterator as $object) {
            $imagePath = $object['Key'];
            if (strcmp($imagePath, $prefix) == 0) {
                continue;
            }
            break;
        }

        $imageData = null;
        if ($imagePath) {
            $result = $this->getPlS3Client()->getObject([
                'Bucket' => $bucket,
                'Key' => $imagePath,
            ]);

            $imageData = (string)$result['Body'];
        }

        return $imageData;
    }

    /**
     * @desc Display a visual cli progress bar
     * @param $done
     * @param $total
     */
    private function progressBar($done, $total) {
        $perc = floor(($done / $total) * 100);
        $left = 100 - $perc;
        $write = sprintf("\033[0G\033[2K[%'={$perc}s>%-{$left}s] - $perc%% - $done/$total categories", "", "");
        fwrite(STDERR, $write);
    }

    /**
     * @desc Saves the hash array to a .txt file
     * @param $array
     */
    private function saveToFile($array)
    {
        $string_data = serialize($array);
        file_put_contents("HashArray.txt", $string_data);
    }

    /**
     * Processes the hash array and filters out all category images that are duplicated
     */
    public function processResults()
    {
        $values = file_get_contents("HashArray.txt");
        $values = unserialize($values);
        foreach($values as $hash => $value) {
            if($value["occurrences"] > 1) {
                $exampleCategory = strstr($value["categoryId"], ",", true);

                $string = "CategoryID: " . $exampleCategory  . " | Occurrences: " . $value["occurrences"] . PHP_EOL;

                file_put_contents("FilteredResults.txt", $string, FILE_APPEND | LOCK_EX);
            }
        }
    }

    /**
     * @desc Downloads the category images and saves them as categoryPictureID.png
     */
    public function saveResultImages()
    {
        $values = file_get_contents("ImageDuplicates.txt");
        $values = unserialize($values);
        foreach($values as $hash => $value) {
            if($value["occurrences"] > 1) {
                $exampleCategory = strstr($value["categoryId"], ",", true);

                $categoryFetch = $this->plDb->prepare("SELECT * FROM meal_categories WHERE id = :id");
                $categoryFetch->bindParam(":id", $exampleCategory);
                $categoryFetch->execute();
                $lfCategory = $categoryFetch->fetchAll();

                $categoryPictureFetch = $this->plDb->prepare("SELECT * from category_picture where id = :id");
                $categoryPictureFetch->bindParam(":id", $lfCategory[0]["categoryPictureId"]);
                $categoryPictureFetch->execute();
                $categoryPicture = $categoryPictureFetch->fetchAll();

                if(isset($categoryPicture)) {
                    $data = $this->downloadLfCategoryImage($lfCategory[0]["categoryPictureId"]);
                    if($data != null) {
                        $im = imagecreatefromstring($data);
                        header('Content-Type: image/png');
                        imagepng($im, "savedImages/" . $exampleCategory . ".png");
                        imagedestroy($im);
                    }
                }
            }
        }
    }


}

$imageCompare = new ImageCompare();
$imageCompare->execute();
