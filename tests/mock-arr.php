<?php
declare(strict_types=1);
header('Content-Type: application/json');
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH) ?: '/';

if($path==='/api/v3/system/status'){
    echo json_encode(['appName'=>'Radarr','version'=>'6.0.0-test']);exit;
}
$movie1=[
    'id'=>1,'tmdbId'=>603,'imdbId'=>'tt0133093','title'=>'The Matrix','year'=>1999,
    'titleSlug'=>'the-matrix-603','monitored'=>true,'hasFile'=>true,'minimumAvailability'=>'released',
    'digitalRelease'=>'1999-09-21T00:00:00Z','physicalRelease'=>'1999-09-21T00:00:00Z',
    'path'=>'/mnt/Movies/The Matrix (1999)','images'=>[],
    'movieFile'=>[
        'id'=>11,'relativePath'=>'The.Matrix.1999.mkv','size'=>1234567890,
        'quality'=>['quality'=>['name'=>'WEBDL-1080p']],
        'languages'=>[['name'=>'English']],
        'mediaInfo'=>['videoCodec'=>'x264','audioCodec'=>'EAC3','audioChannels'=>6]
    ]
];
$movie2=[
    'id'=>2,'tmdbId'=>999999,'title'=>'Future Test Movie','year'=>2030,
    'titleSlug'=>'future-test-movie','monitored'=>true,'hasFile'=>false,'minimumAvailability'=>'inCinemas',
    'inCinemas'=>'2030-01-01T00:00:00Z','path'=>'/mnt/Movies/Future Test Movie (2030)','images'=>[]
];

if($path==='/api/v3/movie'){echo json_encode([$movie1,$movie2]);exit;}
if($path==='/api/v3/movie/1'){echo json_encode($movie1);exit;}
if($path==='/api/v3/movie/2'){echo json_encode($movie2);exit;}
if(str_starts_with($path,'/api/v3/blocklist/movie')){echo '[]';exit;}
if(str_starts_with($path,'/api/v3/queue/details')){echo '[]';exit;}
if(str_starts_with($path,'/api/v3/history/movie')){echo '[]';exit;}
if(str_starts_with($path,'/api/v3/release')){echo '[]';exit;}

http_response_code(404);
echo json_encode(['error'=>'not found','path'=>$path]);
