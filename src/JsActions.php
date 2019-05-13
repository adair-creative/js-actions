<?php

namespace AdairCreative {
	use SilverStripe\ORM\DataExtension;
	use SilverStripe\Control\HTTPRequest;
    use SilverStripe\View\Requirements;
    use SilverStripe\Dev\Debug;
    use SilverStripe\Core\Injector\Injector;
    use Psr\SimpleCache\CacheInterface;
    use SilverStripe\Core\Flushable;
    use SilverStripe\ORM\DataObject;
    use SilverStripe\Core\Config\Configurable;
    use SilverStripe\Core\Config\Config;

	class JsActionsController extends DataExtension implements Flushable {
		use Configurable;	

		private static $url_handlers = [
			'actionable//$Action' => "actionable"
		];

		private static $allowed_actions = [
			"actionable"
		];

		public static function flush() {
			Injector::inst()->get(CacheInterface::class . ".ACG_JsActions")->clear();
		}

		private function actionPrefix(): string {
			$prefix = Config::inst()->get(JsActionsController::class, "action_prefix");
			return $prefix == null ? "action" : $prefix;
		}

		private function getDataObject(string $param, int $id, &$dataObject) {
			if (strpos($param, "__") > 0) {
				if (is_callable(substr($param, 0, strpos($param, "__")) . "::get")) {
					if ($dataObject = substr($param, 0, strpos($param, "__"))::get()->byID($id)) {
						return 0;
					}
					else {
						return 1;
					}
				}
			}
			return 2;
		}

		public function actionable(HTTPRequest $request) {
			$prefix = $this->actionPrefix();
			if ($action = $request->param("Action")) {
				if (method_exists($this->owner, $prefix . "_" . $action)) {
					$vars = $request->getVars();
					$keys = array_keys($vars);
					$args = array_values($vars);
					
					for ($a = 0; $a < count($keys); $a++) {
						$dataObject = null;
						$res = $this->getDataObject($keys[$a], $args[$a], $dataObject);
						if ($res != 2) {
							if ($res == 0) {
								$args[$a] = $dataObject;
							}
							else {
								user_error("Invalid ID Javascript Action call, (" . $this->owner->ClassName . ", " . $action . ") " . substr($keys[$a], 0, strpos($keys[$a], "__")) . "[" . $args[$a] . "]", E_USER_ERROR);
							}
						}
					}

					array_push($args, $request);

					return call_user_func([$this->owner, $prefix . "_" . $action], ...$args);
				}
			}
		}

		public function onBeforeInit() {
			$prefix = $this->actionPrefix();
			$jsNamespace = Config::inst()->get(JsActionsController::class, "js_namespace");
			$cache = Injector::inst()->get(CacheInterface::class . ".ACG_JsActions");
			$cacheName = $this->owner->ClassName . "_ACG_JsActionBinding";
			if ($js = $cache->get($cacheName)) {
				Requirements::customScript($js);
				return;
			}

			if ($jsNamespace == null) $jsNamespace = "Controller";

			$javasript = "const " . $jsNamespace . "={";
			foreach (get_class_methods($this->owner) as $method) {
				if (substr($method, 0, strlen($prefix . "_")) == $prefix . "_") {
					$name = str_replace($prefix . "_", "", $method);
					$argNames = [];
					$argTypes = [];
					$argDefaults = [];
					$argParams = "";

					foreach ((new \ReflectionMethod($this->owner, $method))->getParameters() as $param) {
						$type = $param->hasType() ? $param->getType() : false;

						if ($type != false) {
							assert($type instanceof \ReflectionNamedType);
						}

						if ($type != HTTPRequest::class) {
							array_push($argNames, ($type && is_subclass_of($type->getName(), DataObject::class) ? $type->getName() . "__" : "") . $param->name);

							if ($param->hasType()) {
								if (is_subclass_of($type->getName(), DataObject::class)) {
									$type = "number";
								}
								else {
									switch ($type->getName()) {
										case "string":
										$type = "string";
										break;
										case "bool":
										$type = "boolean";
										break;
										case "float":
										case "int":
										case "double":
										$type = "number";
										break;
									}
								}

								array_push($argTypes, $type);
							}
							else {
								array_push($argTypes, null);
							}

							array_push($argDefaults, $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null);
						}
					}

					$javasript .= $name . ":function(";

					for ($a = 0; $a < count($argNames); $a++) {
						$javasript .= ($a > 0 ? "," : "") . $argNames[$a] . ($argDefaults[$a] != null ? "=" . $argDefaults[$a] : "");
					}

					$javasript .= "){";

					for ($a = 0; $a < count($argNames); $a++) {
						$argParams .= ($a == 0 ? "?" : "&") . $argNames[$a] . "=\"+encodeURI(" . $argNames[$a] . ")+\"";
						if ($argTypes[$a] != null) $javasript .= "if(typeof " . $argNames[$a] . " == \"" . $argTypes[$a] . "\"){";
					}

					$javasript .= "return new function(){";
					$javasript .= "var self=this;";
					$javasript .= "this.then=function(callback){";
					$javasript .= "var xhttp=new XMLHttpRequest();";
					$javasript .= "xhttp.onreadystatechange=function() {";
					$javasript .= "if (this.readyState==4) {";
					$javasript .= "callback(xhttp.responseText,this);";
					$javasript .= "}};";
					$javasript .= "xhttp.open(\"GET\",\"" . $this->owner->URLSegment . "/actionable/" . $name . $argParams . "\");";
					$javasript .= "xhttp.send();}};";

					for ($a = count($argNames) - 1; $a >= 0; $a--) {
						if ($argTypes[$a] != null) $javasript .= "} else {console.error(\"TypeError: Invalid argument type for \\\"" . $argNames[$a] . "\\\" expected typeof \\\"" . $argTypes[$a] . "\\\"\");}";
					}
					$javasript .= "},";
				}
			}

			$javasript .= "}";

			$cache->set($cacheName, $javasript);

			Requirements::customScript($javasript);
		}
	}
}