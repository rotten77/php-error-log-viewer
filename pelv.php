<?php

include dirname(__FILE__) . '/pelv.config.php';
session_start();

if(isset($_GET['log_file'])) {
	$_SESSION['log_file'] = $_GET['log_file'];
}

if(isset($_POST['password'])) {
	if($_POST['password']==$pelv['password'] && $_POST['user']==$pelv['user']) {
		$_SESSION['user'] = $pelv['user'];
		$_SESSION['log_file'] = '';
	} else {
		unset($_SESSION);
		session_destroy();
	}	
}

if(isset($_REQUEST['logout'])) {
	unset($_SESSION);
	session_destroy();
}

$log_file = isset($_SESSION['log_file']) ? $_SESSION['log_file'] : '';

if(!isset($_SESSION) || !isset($_SESSION['user']) || $_SESSION['user']=='') {
$login_form = '
<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
<html class="md-theme-default"><head>
<title>Login Required</title>
<meta content="width=device-width,initial-scale=1,minimal-ui" name="viewport">
<link href="https://fonts.googleapis.com/css?family=Roboto+Mono" rel="stylesheet">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,400italic|Material+Icons">
<link rel="stylesheet" href="https://unpkg.com/vue-material@beta/dist/vue-material.min.css">
<link rel="stylesheet" href="https://unpkg.com/vue-material@beta/dist/theme/default-dark.css">
<style>
#app {
	max-width:200px;
	margin:14px;
}
.md-primary {
	background:rgb(213,0,0);
	color:#fff;
}
.md-has-placeholder::placeholder {
	color:#888;
}
</style>
</head><body><div id="app">
<form method="post" action="?select_log">
<div class="md-field md-toolbar-section-end md-theme-default md-clearable md-has-placeholder"><input type="text" name="user" class="md-input" placeholder="User" /></div>
<div class="md-field md-toolbar-section-end md-theme-default md-clearable md-has-placeholder"><input type="password" name="password" class="md-input" placeholder="Password" /></div>
<div><button type="submit" name="login" class="md-raised md-primary md-button md-ink-ripple">Login</button></div>
</form>
</body></html>
';
	die($login_form);
}

if((isset($_SESSION) && isset($_SESSION['user']) && $_SESSION['user']==$pelv['user']) && ($log_file=='' || isset($_GET['select_log']))) {

	$select_log = '
	<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
	<html class="md-theme-default"><head>
	<title>Select log file</title>
	<meta content="width=device-width,initial-scale=1,minimal-ui" name="viewport">
	<link href="https://fonts.googleapis.com/css?family=Roboto+Mono" rel="stylesheet">
	<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,400italic|Material+Icons">
	<link rel="stylesheet" href="https://unpkg.com/vue-material@beta/dist/vue-material.min.css">
	<link rel="stylesheet" href="https://unpkg.com/vue-material@beta/dist/theme/default-dark.css">
	<style>
	#app {
		max-width:200px;
		margin:14px;
	}
	.md-primary {
		background:rgb(213,0,0);
		color:#fff;
	}
	</style>
	</head><body><div id="app">';

	$log_directory = array_diff(scandir($pelv['log_folder']), array('..', '.'));

	foreach($log_directory as $filename) {
		$select_log.= '<p><a href="?log_file='.$filename.'">'.$filename.'</a></p>';
	}

	if(count($log_directory)==0) {
		$select_log.= '<p>No files in defined folder</p>';
	}
	
	$select_log.= '</div>
	</body></html>
	';
	die($select_log);
}

/**
 * PHP Error Log Viewer.
 * Check readme.md for more information.
 *
 *  * Disclamer
 * - This contains code for deleting your log-file.
 * - It is meant for development-environments
 */
$pelv_settings = array(
	file_path => $pelv['log_folder'] . $log_file,
);

$pelv_log_handler = new Pelv_Log_Handler( $pelv_settings );

$pelv_log_handler->handle_ajax_requests();

/**
 * Read, process, delete log files. Output as json.
 */
class Pelv_Log_Handler {

	/**
	 * Contains grouped content of the log file.
	 *
	 * @var array
	 */
	public $content = array();

	/**
	 * Index used for grouping same messages.
	 * Created via crc32().
	 *
	 * @var int[]
	 */
	public $index = array();

	/**
	 * The size of the file.
	 *
	 * @var int
	 */
	public $filesize = 0;

	/**
	 * The settings which are being applied.
	 *
	 * @var array
	 */
	public $settings = array();

	/**
	 * The default setting
	 *
	 * @var array
	 */
	public $default_settings = array(
		'file_path'           => 'debug.log',
		'vscode_links'        => true, // Stack trace references files. link them to your repo (https://code.visualstudio.com/docs/editor/command-line#_opening-vs-code-with-urls).
		'vscode_path_search'  => '', // This is needed if you develop on a vm. like '/srv/www/...'.
		'vscode_path_replace' => '', // The local path to your repo. like 'c:/users/...'.
	);

	public function __construct( $settings ) {
		$this->settings = array_merge( $this->default_settings, $settings );
	}

	public function handle_ajax_requests() {
		$this->ajax_handle_errors();
		if ( isset( $_GET['get_log'] ) ) {
			$this->ajax_json_log();
		}
		if ( isset( $_GET['delete_log'] ) ) {
			$this->ajax_delete();
		}
		if ( isset( $_GET['filesize'] ) ) {
			$this->ajax_filesize();
		}
	}

	public function ajax_handle_errors() {
		$used = array_diff( array( 'get_log', 'delete_log', 'filesize' ), array_keys( $_GET ) );
		if ( count( $used ) === 3 ) {
			return;
		}
		$log_file_valid = $this->is_file_valid();
		if ( ! $log_file_valid ) {
			$this->ajax_header();
			echo $log_file_valid;
			die();
		}
	}

	/**
	 * Read the log-file.
	 *
	 * @return string|false The read string or false on failure.
	 */
	public function get_file() {
		$my_file = fopen( $this->settings['file_path'], 'r' );
		$size    = $this->get_size();
		return ( $my_file && $size ) ? fread( $my_file, $size ) : false;
	}

	/**
	 * Get the size of the log-file.
	 *
	 * @return int|false The size of the log file in bytes or false.
	 */
	public function get_size() {
		if ( empty( $this->filesize ) ) {
			$this->filesize = filesize( $this->settings['file_path'] );
		}
		return $this->filesize;
	}

	/**
	 * Check if a file is valid.
	 *
	 * @return boolean|string true or error message.
	 */
	public function is_file_valid() {
		if ( ! file_exists( $this->settings['file_path'] ) ) {
			return 'The file you specified does not exist (' . $this->settings['file_path'] . ')';
		}
		if ( 0 == $this->get_size() ) {
			return 'Your log file is empty.';
		}
		$mbs = $this->get_size() / 1024 / 1024; // in MB.
		if ( $mbs > 100 ) {
			if ( ! isset( $_GET['ignore'] ) ) {
				return( "Aborting. debug.log is larger than 100 MB ($mbs).
					If you want to continue anyway add the 'ignore' queryvar"
				);
			}
		}
		return true;
	}

	/**
	 * Triggers preg_replace_callback which calls
	 * replace_callback function which stores values in $this->content.
	 *
	 * @param string $raw The content of the log file.
	 * @return void
	 */
	public function parse( $raw ) {
		$error = preg_replace_callback( '~^\[([^\]]*)\]((?:[^\r\n]*[\r\n]?(?!\[).*)*)~m', array( $this, 'replace_callback' ), $raw );
	}

	public function link_vscode_files( $string ) {
		$string = preg_replace_callback( '$([A-Z]:)?([\\\/][^:(\s]+)(?: on line |[:\(])([0-9]+)\)?$', array( $this, 'vscode_link_filter' ), $string );
		return $string;
	}

	public function vscode_link_filter( $matches ) {
		$link = 'vscode://file/' . $matches[1] . $matches[2] . ':' . $matches[3];
		$root = isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : $_SERVER['DOCUMENT_ROOT'];
		$val  = parse_url( $root, PHP_URL_QUERY );
		parse_str( $val, $get_array );
		$link = str_replace( $this->settings['vscode_path_search'], $this->settings['vscode_path_replace'], $link );
		return "<a href='$link'>" . $matches[0] . '</a>';
	}

	/**
	 * Callback function which is triggered by preg_replace_callback.
	 * Doesn't return but writes to $this->content.
	 *
	 * @param array $arr
	 * looks like that:
	 * array (
	 *      0   =>  [01-Jun-2016 09:24:02 UTC] PHP Fatal error:  Allowed memory size of 456 bytes exhausted (tried to allocate 27 bytes) in ...
	 *      1   =>  [01-Jun-2016 09:24:02 UTC]
	 *      2   =>  PHP Fatal error:  Allowed memory size of 56 bytes exhausted (tried to allocate 15627 bytes) in ... *
	 * )
	 * @return void
	 */
	public function replace_callback( $arr ) {
		$err_id = crc32( trim( $arr[2] ) ); // create a unique identifier for the error message.
		if ( ! isset( $this->content[ $err_id ] ) ) { // we have a new error.
			$this->content[ $err_id ]        = array();
			$this->content[ $err_id ]['id']  = $err_id; // err_id.
			$this->content[ $err_id ]['cnt'] = 1; // counter.
			$this->index[] = $err_id;
		} else { // we already have that error...
			$this->content[ $err_id ]['cnt']++; // counter.
		}

		$date = date_create( $arr[1] ); // false if no valid date.
		$this->content[ $err_id ]['time'] = $date ? $date->format( DateTime::ATOM ) : $arr[1]; // ISO8601, readable in js
		$message = htmlspecialchars( trim( $arr[2] ), ENT_QUOTES );
		$this->content[ $err_id ]['msg'] = $this->settings['vscode_links'] ? $this->link_vscode_files( $message ) : $message;
		$this->content[ $err_id ]['cls'] = implode(
			' ',
			array_slice(
				str_word_count( $this->content[ $err_id ]['msg'], 2 ),
				1,
				2
			)
		); // the first few words of the message become class items.
	}

	public function delete() {
		if ( ! file_exists( $this->settings['file_path'] ) ) {
			return 'there was no file to delete';
		}
		if ( ! is_writeable( realpath( $this->settings['file_path'] ) ) ) {
			return 'debug.log is not writable';
		}
		$f = @fopen( $this->settings['file_path'], 'r+' );
		if ( $f !== false ) {
			ftruncate( $f, 0 );
			fclose( $f );
			return 'emptied file';
		} else {
			return 'file could not be emptied';
		}
	}

	public function ajax_header() {
		header( 'Content-Type: application/json' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Cache-Control: post-check=0, pre-check=0', false );
		header( 'Pragma: no-cache' );
	}

	public function ajax_json_log() {
		$this->ajax_header();
		$file = $this->get_file();
		if ( ! $file ) {
			die( "File is empty or can't be opened." );
		}
		$this->parse( $file ); // writes to $this->content. preg_replace_callback is odd.
		echo( json_encode( array_values( $this->content ) ) );
		die();
	}

	public function ajax_delete() {
		$this->ajax_header();
		echo $this->delete();
		die();
	}

	public function ajax_filesize() {
		$this->ajax_header();
		echo json_encode( $this->get_size() );
		die();
	}
}
?>
<html>
<head>
<script src="https://unpkg.com/vue@2.6.11"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/axios/0.18.0/axios.min.js"></script>
<script src="https://unpkg.com/vue-material"></script>
<meta content="width=device-width,initial-scale=1,minimal-ui" name="viewport">
<link href="https://fonts.googleapis.com/css?family=Roboto+Mono" rel="stylesheet">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,400italic|Material+Icons">
<link rel="stylesheet" href="https://unpkg.com/vue-material@beta/dist/vue-material.min.css">
<link rel="stylesheet" href="https://unpkg.com/vue-material@beta/dist/theme/default-dark.css">
</head>
<body>
<div id="app">
	<div class="loader" v-bind:class="{'visible': loading }">
		<md-progress-bar md-mode="query"></md-progress-bar>
	</div>
	<md-table v-model="rowsDisplay" :md-sort.sync="currentSort" :md-sort-order.sync="currentSortOrder"ref="mytable" md-card md-fixed-header>
		<md-chip v-if="filesize">{{ readableFilesize() }}<!--needed for the inner filesize container to update--></md-chip>
		<md-table-toolbar>
			<h1 class="md-title" ><a href="?select_log"><?php echo $_SESSION['log_file']; ?> <md-chip v-if="filesize">{{ readableFilesize() }}</a></md-chip></h1>
			<div class="md-toolbar-section-start">
			</div>
			<md-field md-clearable class="md-toolbar-section-end">
				<md-input placeholder="Filter Rows (Regex)" v-model="search" @input="searchTable" />
			</md-field>
			<div class="md-toolbar-section-end">
				<md-switch v-model="autoreload" class="md-primary">Autoreload</md-switch>
				<md-button @click="deleteLog()" class="md-fab md-raised md-accent" :disabled="filesize == 0 ? true : false" >
					<md-icon>delete</md-icon>
					<md-tooltip md-direction="top">Empty file. This can not be undone.</md-tooltip>
				</md-button>
			</div>
		</md-table-toolbar>
		<md-table-empty-state v-if="filesize && search"
			md-label="Nothing found"
			:md-description="`No results for your search: '${search}'. Try a different search term or create a matching error ;)`">
		</md-table-empty-state>
		<md-table-empty-state v-if="!filesize"
			md-label="Nothing found"
			:md-description="`Looks like the file does not exist or is empty.`">
		</md-table-empty-state>
		<md-table-empty-state v-if="error"
			:md-label="errorMessage"
		>
		</md-table-empty-state>
		<md-table-empty-state v-if="loading && filesize"
			md-label="Loading"
			:md-description="`The file has a size of ${ readableFilesize() }`">
		</md-table-empty-state>
		<md-table-row slot="md-table-row" slot-scope="{ item }">
			<md-table-cell :error="item.cls" md-label="Count" md-sort-by="cnt">{{ item.cnt }}</md-table-cell>
			<md-table-cell :error="item.cls" style="min-width:140px" md-label="Time" md-sort-by="time">
				<time :datetime="item.time">{{ readableDateTime(item.time) }}</time>
			</md-table-cell>
			<md-table-cell class="message" md-label="Message" md-sort-by="msg" ><pre v-html="item.msg">{{ item.msg }}</pre></md-table-cell>
		</md-table-row>
	</md-table>
</div>
<script>
const toLower = text => {
	return text.toString().toLowerCase()
}
const searchByName = (items, term) => {
	if (term) {
		var regex = new RegExp( toLower(term), 'gim');
		return items.filter(item => regex.test(item.msg));
		//return items.filter(item => toLower(item.msg).includes(toLower(term)))
	}
	return items
}
Vue.use(VueMaterial.default)
var app = new Vue({
	el: '#app',
	data: () => ({
		currentSort: 'time',
		currentSortOrder: 'desc',
		search: null,
		rowsRaw: [],
		rowsDisplay: [],
		filesize: 0,
		loading: false,
		delete: '',
		autoreload: true,
		error: false,
		errorMessage: 'Something went wrong',
		documentHidden: false,
	}),
	mounted(){
		this.update(this);
		var self = this;
		var timeout = setInterval(this.update, 4000, this);
		document.addEventListener("visibilitychange", function() {
			self.documentHidden = document.hidden;
		});
	},
	methods: {
		readableFilesize: function(){
			if ( this.filesize > (1024 * 1024) ){ // filesize is in bytes
				return (Math.round(this.filesize /1024 /102 ) /10) + ' MB';
			} else {
				return (Math.round(this.filesize /102 ) /10) + ' KB';
			}
		},
		readableDateTime( dateTimeString ){
			let date = new Date(dateTimeString);
			return isNaN( date ) ? dateTimeString : date.toLocaleString();
		},
		filterSearch(){
			if ( this.search == "" ){
				return this.rowsRaw
			} else {
				return searchByName(this.rowsRaw, this.search)
			}
		},
		searchTable () {
			this.rowsDisplay = this.filterSearch();
		},
		compareEntries() {
			const sortBy = this.currentSort
			const multiplier = this.currentSortOrder === 'desc' ? -1 : 1;
			return ( a, b ) => {
				const aAttr = a[sortBy];
				const bAttr = b[sortBy];
				if (aAttr === bAttr) {
					return 0
				}
				else if (typeof aAttr === 'number' && typeof bAttr === 'number') {
					return (aAttr - bAttr) * multiplier // numerical compare, negate if descending
				}
				return String(aAttr).localeCompare(String(bAttr)) * multiplier;
			}
		},
		setNewData(response){
			this.rowsRaw = response.data
			this.loading = false
			this.rowsDisplay = this.filterSearch();
			this.rowsDisplay.sort( this.compareEntries() );
		},
		getLog(comp){
			axios.get('?get_log').then( response => (comp.setNewData(response)))
		},
		update(comp){
			if ( ! comp.autoreload | comp.documentHidden ){
				console.log( 'autoreload is disabled or window is hidden');
				return;
			}
			if (comp.loading == true){
				console.log('looks like loading didn\'t finish yet.');
				return;
			}
			comp.loading = true
			comp.getFilesize((response)=>{
				let size = response.data
				if ( typeof response.data == 'string'){
					console.log('something went wrong...')
					comp.rowsDisplay = [];
					comp.error = true
					comp.errorMessage = response.data;
					comp.loading = false
					comp.filesize = 0;
					return
				}
				if ( size != comp.filesize ) {
					comp.filesize = size
					comp.getLog(comp)
				} else {
					console.log('nothing changed')
					comp.loading = false
				}
			})
		},
		getFilesize( cb ){
			axios.get('?filesize')
			.then(response => (cb( response )))
			.catch(error => {
				console.log( error.response )
			})
		},
		deleteLog(){
			this.rowsDisplay = [];
			this.filesize = 0;
			axios.get('?delete_log').then(response => (this.delete.data = response))
		}
	}
})
</script>
<style>
	#app { padding: 0 10px; }
	.loader {transition: opacity 1s; opacity: 0;}
	.loader.visible { transition: opacity 0s; opacity: 1 }
	.md-card { height: calc(100vh - 15px); min-width: 90vw; max-width: 98vw; overflow: hidden; }
	.md-content { max-height: unset !important; height: calc(100vh - 15px); max-width: 100%; }
	.md-table-cell[error*="Warning"] { background-color: #FFA66F; }
	.md-table-cell[error*="Fatal"], .md-table-cell[error*="error"] { background-color: #d05f5f !important}
	pre { font-family: 'Roboto Mono', monospace; margin: 0.2rem }
	.md-table-cell { height: auto; vertical-align: top; }
	.cell-time { min-width: 200px }
	.md-table-fixed-header {max-width: 100%}
	.message {white-space: pre;}
	.message a { color: #a8c9ff }
</style>
</body>
</html>
