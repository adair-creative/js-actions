# Javascript Actions `!BETA!`
A tool that allows for easy actions to easily be called from the client Javascript, featuring typed arguments, automatic method execution, DataObject getting by IDs & default values!

Note: This is just a beta so it may be unstable and there will be more to come

```composer require adair-creative\js-actions```


## Usage
PHP
```php
class MyPage extends PageController {
	public function action_searchProducts(string $name, int $maxResults = 10, HTTPRequest $request) {
		return json_encode(Product::get()->filter("Name", $name)->limit($maxResults));
	}
}
```

Javascript
```javascript
if ($(document.body).hasClass("MyPage")) {
	Controller.searchProducts($(".search").val()).then(result => {
		// ...
	});
}
```

## Config
```yaml
AdairCreative\JsActionsController:
    - js_namespace: "MyController"
    - action_prefix: "MyAction"
```
