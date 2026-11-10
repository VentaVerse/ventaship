# API authentication guide

- Source: https://spx.ph/integration/en/guide/bdc52775bd044b5eab12342a99552b1b
- Create Time: 08/06/2022
- Last Updated: 17/10/2024
- Harvested: 2026-11-10

---

## Get app-ID and app-Secret

The open platform assigns a unique set of app-id and app-secret to each accessed app.

For example：

app-id = 100000

app-secret = H25HY53GO4BA2GQ

## Generates the signature content check-sign

1. The following details are needed to generate check-sign
2. app-id, app-secret, timestamp (in seconds, type: int64), random-num (type: int64) set in the request header
3. payload in JSON format set in the request body (need to be converted to a string before input to GenerateCheckSign)
4. The check-sign signature parameter is implemented via HMAC-SHA256 algorithm.
5. The process of user generated check sign needs to be the same as the example below.
6. The unit of timestamp needs to be second not millisecond.

The following is an example of generating check-sign in Go/Python3/Node.js/Java/PHP/C# language:

```
package main

import (
    "bytes"
    "crypto/hmac"
    "crypto/sha256"
    "encoding/hex"
    "encoding/json"
    "fmt"
    "io/ioutil"
    "math/rand"
    "net/http"
    "strconv"
    "time"
)

const _checkSignFormatStr = "%d_%d_%d_%s"

type HttpReqData struct {
    UserId      uint64 `json:"user_id"`
    UserSecret  string `json:"user_secret"`
    ServiceType uint32 `json:"service_type"`
}

func main() {
    var appId = uint64(100000)
    var appSecret = "H25HY53GO4BA2GQ"
    var timestamp = time.Now().Unix()
    rand.Seed(timestamp)
    var randomNum = rand.Int63()
    reqData := &HttpReqData{
	UserId:      239404781503925,
    	UserSecret:  "85f0d570-a265-4d9d-857e-b30aa57c4fbe",
    	ServiceType: 1,
    }
    payload, err := json.Marshal(reqData)
    if err != nil {
	return
    }
    // Generate check sign
    checkSign, err := GenerateCheckSign(appId, appSecret, timestamp, randomNum, payload)
    if err != nil {
	return
    }
    fmt.Println(checkSign)

    // Do http request
    httpReq, err := http.NewRequest(http.MethodPost, "https://test-stable.spx.vn/open/api/v1/order/get_pickup_time", bytes.NewReader(payload))
    if err != nil {
	return
    }
    httpReq.Header.Set("app-id", strconv.FormatUint(appId, 10))
    httpReq.Header.Set("check-sign", checkSign)
    httpReq.Header.Set("timestamp", strconv.FormatInt(timestamp, 10))
    httpReq.Header.Set("random-num", strconv.FormatInt(randomNum, 10))
    httpResp, err := http.DefaultClient.Do(httpReq)
    if err != nil {
	return
    }
    defer httpResp.Body.Close()
    body, err := ioutil.ReadAll(httpResp.Body)
    if err != nil {
	return
    }
    print(string(body))
}

func GenerateCheckSign(appId uint64, secret string, timestamp, random int64, payload []byte) (string, error) {
    originalValue := fmt.Sprintf(_checkSignFormatStr, appId, timestamp, random, payload)
    h := hmac.New(sha256.New, []byte(secret))
    _, err := h.Write([]byte(originalValue))
    if err != nil {
	return "", err
    }
    checkSign := hex.EncodeToString(h.Sum(nil))
    return checkSign, nil
}
```

## Test your generate check-sign method:

We have provided a set of test data and the expected correct results should be returned. Please use the data below to check that your own method of generating check-sign produces the same results.

Test data:

| parameter | data type | value |
| --- | --- | --- |
| app-id | integer | 100000 |
| app-secret | string | H25HY53GO4BA2GQ |
| timestamp | integer | 1677918414 |
| random-num | integer | 926611981 |
| payload | string | note: Shown in the code block below. Please keep the content exactly the same, including the format. |

#### payload value :

```
{"user_id":239404781503925,"user_secret":"85f0d570-a265-4d9d-857e-b30aa57c4fbe","service_type":1}
```

### Expected result (string):

”97e21b23940e4ddc96fb4d2474f02425353d2b0e23aee384f3f94c3d0b9ba17d”

### Note:

If the results are not the same you can troubleshoot in the following ways:

1. Check whether the data type is correct
2. Check whether the HMAC-SHA256 algorithm is used.
3. Check whether the format of the payload is consistent with that provided, line feeds and different spaces will lead to different generated results.
4. If you are using Windows OS for development and testing, please refer to the Windows version at the bottom.

## For Windows development&testing environment:

When using Postman for interface testing, the request body includes newlines that are not explicitly displayed in addition to the visible characters entered by the user. The line break is parsed as \n on unix systems, and \r\n on windows systems. This results in the actual payload byte [] for the same request body with different content on different systems. In this case, the same encryption process must generate different check-sign.

So, below we also provide the expected results of generating signatures using windows system test. If your development environment is windows or you are using a windows operating system to test the check-sign algorithm, when your payload has line breaks and you copy the payload we provide into Postman, the line breaks will be represented as \r\n. In this case, using the test data above, the result generated by the standard algorithm should be:

### Expected result (string):

”97e21b23940e4ddc96fb4d2474f02425353d2b0e23aee384f3f94c3d0b9ba17d”

If your results are not the same as both of the above expected results, then you will need to follow our guidelines to check and revise your algorithm again.

### Note:

In order to eliminate the problems caused by the differences between different OS, you need to inform our staff if you use Windows for development & testing, and you are generating signatures by copying data and pasting it into a Unix OS to generate the result. For example: using an online website "https://xxx.com " to generate a signature (the website line break corresponding to the binary content of \n), and then put the signature results in Postman(here the request body line break is \r\n) to test API, so you will encounter the problem that the content and signature results do not match, the system will return check-sign verification failed in response.

In this way, at the stage of debugging with the platform, we will provide a compatible cross-platform solution. But subsequently, for the sake of the uniformity of the protocol and the accuracy of the message and security considerations, we will assist you to solve the problem of inconsistency in line feeds between Unix and Windows. After that, the API calling live environment will only accept the unique check-sign that meets the specification.

### 
