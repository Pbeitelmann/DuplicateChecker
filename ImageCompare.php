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
    private function getTkS3Client()
    {
        $tkS3Client = new Aws\S3\S3Client([
            'version' => 'latest',
            'region' => 'eu-west-1',
            'credentials' => [
                'key' => $this->config['takeawayS3']['key'],
                'secret' => $this->config['takeawayS3']['secret'],
            ],
        ]);
        return $tkS3Client;
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

    public function logImageHashes()
    {
        $lfFetch = $this->lfDb->prepare("SELECT `id`
                                            FROM `restaurants`
                                            WHERE `status`
                                            NOT IN ('11','18', '19', '30', '31')");
        $lfFetch->execute();
        $activeRestaurantIds = $lfFetch->fetchAll();

        foreach($activeRestaurantIds as $restaurantId)
        {
            echo("Checking Restaurant with ID:" . $restaurantId["id"] . PHP_EOL);
            $categoryFetch = $this->lfDb->prepare("SELECT * FROM meal_categories WHERE restaurantId = :id");
            $categoryFetch->bindParam(":id", $restaurantId["id"]);
            $categoryFetch->execute();
            $lfCategories = $categoryFetch->fetchAll();

            $i = 0;
            foreach($lfCategories as $lfCategory)
            {


                $categoryPictureFetch = $this->lfDb->prepare("SELECT * from category_picture where id = :id");
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
                            $this->hashArray[md5($data)]["categoryId"] .= ", " . $lfCategory["id"];;
                        } else {
                            $this->hashArray[md5($data)]["categoryId"] = $lfCategory["id"];
                        }

                        if(isset($this->hashArray[md5($data)]["categoryPictureId"])) {
                            $this->hashArray[md5($data)]["categoryPictureId"] .= ", " . $categoryPicture[0]["id"];
                        } else {
                            $this->hashArray[md5($data)]["categoryPictureId"] = $categoryPicture[0]["id"];
                        }
                    }
                }
                $this->progressBar(++$i, sizeof($lfCategories));
            }
            echo(PHP_EOL);
            $this->saveToFile($this->hashArray);
        }
    }

    public function checkHashArray()
    {
        foreach($this->hashArray as $key => $value)
        {
            if($value["occurrences"] > 1) {
                echo("Duplicates found. Categories: " . PHP_EOL);
                echo($value["categoryPictureId"] . PHP_EOL);
            }
        }
    }
    /**
     * @param $categoryPictureId
     * @return null|string
     */
    private function downloadLfCategoryImage($categoryPictureId)
    {
        $bucket = $this->config['lieferandoS3']['bucket'];
        $prefix = "category_pictures/{$categoryPictureId}/";

        $s3Iterator = $this->getLfS3Client()->getIterator('ListObjects', [
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
            $result = $this->getLfS3Client()->getObject([
                'Bucket' => $bucket,
                'Key' => $imagePath,
            ]);

            $imageData = (string)$result['Body'];
        }

        return $imageData;
    }

    private function progressBar($done, $total) {
        $perc = floor(($done / $total) * 100);
        $left = 100 - $perc;
        $write = sprintf("\033[0G\033[2K[%'={$perc}s>%-{$left}s] - $perc%% - $done/$total categories", "", "");
        fwrite(STDERR, $write);
    }

    private function saveToFile($array)
    {
        $string_data = serialize($array);
        file_put_contents("ImageDuplicates.txt", $string_data);
    }

}

$imageCompare = new ImageCompare();
$imageCompare->execute();