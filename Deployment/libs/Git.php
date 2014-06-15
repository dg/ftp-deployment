<?php

/**
 * This class is used to download repositories from github, bitbucket etc.
 * Most easy use is to create instance with one constructor argument that represents url to repository zip
 * and then set $zip_url to true and call download_repo() and then unpack_repo($zip, $extract_to) to get repo 
 * extracted to your local manchine in folder of your choice.
 */
class GitDownloader {

	/** @var repository URL*/
	private $repo;

	/** @var username to get access to repo */
	private $username;

	/** @var password to get acces to repo */
	private $password;

	/** @var path to folder, where fetched .zip repos are stored */
	protected $temporary_folder_path = 'repos';

	/** @var if is true, URL schemes are not needed, everything is done using repo zip url **/
	public $zip_url = false;

	/** @var url schemes for repo providers */
	var $url_schemes = array('bitbucket' => array( 'url_scheme' => 'https://bitbucket.org/[username]/[reponame]',
		                                           'zip_url_scheme' => 'https://[username]:[password]@bitbucket.org/[username]/[reponame]/get/[branch].zip'
		                                ),
							 'github' => array('url_scheme' => 'https://github.com/[username]/[reponame]/[branch]',

							 		),
							);


	/** @var name of deployment.ini sample */
	var $deployment_sample = 'deployment_sample.ini';

	/**
	 * Constructor accepts $repo - repository URL as an argument
	 */
	function __construct( $repo, $username = null, $password = null ) 
	{
		$this->repo = $repo;	

		if ($password !== NULL)
		{
			$this->username = $username;	
			$this->password = $password;	
		} 
	
	}

	/**
	 * Sets folder where are repo zips downloaded
	 * @param string $path path to folder
	 */
	public function setTemporaryFolderPath( $path )
	{
		if ( is_dir($path) )
		{
			$this->temporary_folder_path = $path;
		}
		else
		{
			throw new GitException('Invalid path to temporary folder - given path does not exists or is not a director.');
		}
	}

	/**
	 * Downloads folder from given provider, if zip_url is se to true, params are ignored, and only $this->repo is used
	 * @param  string $provider optional - provider from scheme list
	 * @param  string $branch   optional - branch of repo
	 * @return string           path to downloaded zip
	 */
	public function downloadRepo( $provider = 'bitbucket', $branch = 'master' )
	{

		if ( !$this->zip_url )
		{
			$repo_zip_url_scheme = $this->url_schemes['bitbucket']['zip_url_scheme'];

			try 
			{
				$repo_name = $this->getRepoName($provider);
			} 
			catch (GitException $e) 
			{
				throw new GitException($e);
			}
			
			
			if ( !is_dir($this->temporary_folder_path) ) 
			{
				mkdir($this->temporary_folder_path, 0777);
			}
			
			$repo_folder = str_replace('[username]', $this->username, 
						   str_replace('[password]', $this->password, 
						   str_replace('[reponame]', $repo_name,
						   str_replace('[branch]', $branch, $repo_zip_url_scheme))));
		}
		else
		{
			$repo_folder = $this->repo;

			$parsed_repo_url = parse_url($this->repo);

			$expl = explode('/', $parsed_repo_url['path']);

			$branch = end($expl);
			$repo_name = $expl[2];
		}

			$repo = @file_get_contents($repo_folder);

			if ( !$repo )
			{
				throw new GitException('Repo could not be found.');
			}

		

		$zip_filename = $branch.'_'.date('Y-m-d-H-i').'.zip';

		$path_to_file = array($this->temporary_folder_path, $repo_name, $zip_filename);
		$this->createPath($path_to_file);

		$zip_file_path = implode('/', $path_to_file);

		file_put_contents($zip_file_path, $repo);

		return $zip_file_path;
	}

	/**
	 * Unpacks downloaded repo zip
	 * @param  string $zip_file   path to zip
	 * @param  string $extract_to folder, where zip should be extracted
	 * @return string             path to folder with repo content
	 */
	public function unpackRepo($zip_file, $extract_to)
	{
		$zip = new ZipArchive;

		if ($err_code = $zip->open($zip_file) !== TRUE)
		{
			throw new GitException("Cannot deploy $zip_file. ZipArchive Error code :$err_code - either not a zip file or repo could not be downloaded.");
		}

		if ( !is_dir($extract_to) ) 
		{
			mkdir($extract_to, 0775);
			@chmod($extract_to, 0775);
		}

		$zip->extractTo($extract_to);
		$first_folder_name = $this->getFirstFolder($zip);
		$zip->close();

		return $extract_to.'/'.$first_folder_name;
	}

	/**
	 * Gets first folder in zip's root. Used because content of repositories is no directly in root of downloaded zips
	 * @param  ZipArchive instnace $zip ZipArchive instance
	 * @return stirng      name of first folder
	 */
	public function getFirstFolder($zip)
	{
		for ($i=0; $i < $zip->numFiles; $i++) 
		{ 
			return str_replace('/','',$zip->getNameIndex($i));
		}		
	}



	/**
	 * Creates a path to a file
	 * @param  array/string $path path to file
	 */
	public function createPath( $path )
	{
		if ( is_string($path) ) $path = explode('/', $path);

		foreach($path as $dir)
		{
			if ( strstr($dir, '.') AND strlen($dir) > 2 ) continue;
			
			$path_to_dir = array();
			foreach($path as $final_folder)
			{
				$path_to_dir[] = $final_folder;
				if ( $final_folder == $dir )
				{
					$path_to_dir = implode('/',$path_to_dir);

					if (!is_dir($path_to_dir)) mkdir($path_to_dir, 0777);
					break;
				}
			}
		}
	}

	/**
	 * Gets repo name using $this->repo and set repo scheme
	 *
	 * @param string $provider - provider name e.g.: Bitbucket, Git...
	 * @return string name of repository
	 */
	public function getRepoName($provider)
	{
		if (!isset($this->url_schemes[$provider])) 
		{
			throw new GitException('Provider settings are not available');
		}

		$url_schemes = $this->url_schemes[$provider];

		$url_schemes = str_replace('//', '/', $url_schemes);
		$expl_scheme = explode('/', $url_schemes['url_scheme']);

		$repo_url = str_replace('//', '/', $this->repo);
		$expl_repo = explode('/', $repo_url);

		$repo_parts = array();
		foreach($expl_repo as $i => $segment)
		{
			$scheme_segment = $expl_scheme[$i];
			
			if ( strstr($scheme_segment, '[') AND strstr($scheme_segment, ']')  )
			{
				$part_key = str_replace('[','', str_replace(']','',$scheme_segment));
				$repo_parts[$part_key] = $segment;
			}	
		}

		if ( isset($repo_parts['reponame']) )
		{
			return $repo_parts['reponame'];
		}
		else
		{
			throw new GitException('Git repository name could not be determined');
			
		}
	}


}

class GitException extends Exception {

}